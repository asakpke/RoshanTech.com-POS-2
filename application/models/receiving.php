<?php
class Receiving extends CI_Model
{
	function get_info($receiving_id)
	{
		$this->db->from('receivings');
		$this->db->join('people', 'people.person_id = receivings.supplier_id', 'LEFT');
		$this->db->where('receiving_id',$receiving_id);
		return $this->db->get();
	}
	
	function get_invoice_count()
	{
		$this->db->from('receivings');
		$this->db->where('invoice_number is not null');
		return $this->db->count_all_results();
	}
	
	function get_receiving_by_invoice_number($invoice_number)
	{
		$this->db->from('receivings');
		$this->db->where('invoice_number', $invoice_number);
		return $this->db->get();
	}
	
	function get_invoice_number_for_year($year='', $start_from = 0)
	{
		$year = $year == '' ? date('Y') : $year;
		$this->db->select("COUNT( 1 ) AS invoice_number_year", FALSE);
		$this->db->from('receivings');
		$this->db->where("DATE_FORMAT(receiving_time, '%Y' ) = ", $year, FALSE);
		$this->db->where("invoice_number IS NOT ", "NULL", FALSE);
		$result = $this->db->get()->row_array();
		return ($start_from + $result[ 'invoice_number_year' ] + 1);
	}
	
	function exists($receiving_id)
	{
		$this->db->from('receivings');
		$this->db->where('receiving_id',$receiving_id);
		$query = $this->db->get();

		return ($query->num_rows()==1);
	}
	
	function update($receiving_data, $receiving_id)
	{
		$this->db->where('receiving_id', $receiving_id);
		$success = $this->db->update('receivings',$receiving_data);
	
		return $success;
	}

	function save ($items,$supplier_id,$employee_id,$comment,$invoice_number,$payment_type,$receiving_id=false)
	{
		if(count($items)==0)
			return -1;

		$receivings_data = array(
		'supplier_id'=> $this->Supplier->exists($supplier_id) ? $supplier_id : null,
		'employee_id'=>$employee_id,
		'payment_type'=>$payment_type,
		'comment'=>$comment,
		'invoice_number'=>$invoice_number
		);

		//Run these queries as a transaction, we want to make sure we do all or nothing
		$this->db->trans_start();

		$this->db->insert('receivings',$receivings_data);
		$receiving_id = $this->db->insert_id();


		foreach($items as $line=>$item)
		{
			$cur_item_info = $this->Item->get_info($item['item_id']);


			foreach($item['unit_ids'] as $index => $unit_id)
			{
				$items_received = $item['receiving_quantity'] != 0 ? $item['quantities'][$index] * $item['receiving_quantity'] : $item['quantities'][$index];
				
				$receivings_items_data = array
				(
					'receiving_id'=>$receiving_id,
					'item_id'=>$item['item_id'],
					'line'=>$item['line'],
					'description'=>$item['description'],
					'serialnumber'=>$item['serialnumber'],
					'quantity_purchased'=>$items_received,
					'discount_percent'=>$item['discount'],
					'item_cost_price' => $cur_item_info->cost_price,
					'item_unit_price'=>$item['price'],
					'item_location'=>$item['item_location'],
					'unit_id'=>$unit_id
				);
	
				$this->db->insert('receivings_items',$receivings_items_data);
	
				// update cost price, if changed AND is set in config as wanted
				if($cur_item_info->cost_price != $item['price']
						AND	$this->config->item('receiving_calculate_average_price') == 'receiving_calculate_average_price')
				{
					$this->Item->change_cost_price($item['item_id'],
													$items_received,
													$item['price'],
													$cur_item_info->cost_price);
				}
				//Update stock quantity
				$item_quantity = $this->Item_quantities->get_item_quantity($item['item_id'], $item['item_location'], $unit_id);
				
	            $this->Item_quantities->save(array('quantity'=>$item_quantity->quantity + $items_received,
	                                              'item_id'=>$item['item_id'],
	            								  'initial_quantity'=>$items_received,
	                                              'location_id'=>$item['item_location'],
	            								  'unit_id'=>$unit_id), $item['item_id'], $item['item_location'], $unit_id);
				
				
				$recv_remarks ='RECV '.$receiving_id;
				$inv_data = array
				(
					'trans_date'=>date('Y-m-d H:i:s'),
					'trans_items'=>$item['item_id'],
					'trans_user'=>$employee_id,
					'trans_location'=>$item['item_location'],
					'trans_comment'=>$recv_remarks,
					'trans_inventory'=>$items_received,
					'trans_unit'=>$unit_id
				);
				$this->Inventory->insert($inv_data);
			}

			$supplier = $this->Supplier->get_info($supplier_id);
		}
		$this->db->trans_complete();
		
		if ($this->db->trans_status() === FALSE)
		{
			return -1;
		}

		return $receiving_id;
	}
	
	function delete_list($receiving_ids,$employee_id,$update_inventory=TRUE)
	{
		$result = TRUE;
		foreach($receiving_ids as $receiving_id) {
			$result &= $this->delete($receiving_id,$employee_id,$update_inventory);
		}
		return $result;
	}
	
	function delete($receiving_id,$employee_id,$update_inventory=TRUE)
	{
		// start a transaction to assure data integrity
		$this->db->trans_start();
		if ($update_inventory) {
			// defect, not all item deletions will be undone??
			// get array with all the items involved in the sale to update the inventory tracking
			$items = $this->get_receiving_items($receiving_id)->result_array();
			foreach($items as $item)
			{
				// create query to update inventory tracking
				$inv_data = array
				(
						'trans_date'=>date('Y-m-d H:i:s'),
						'trans_items'=>$item['item_id'],
						'trans_user'=>$employee_id,
						'trans_comment'=>'Deleting receiving ' . $receiving_id,
						'trans_location'=>$item['item_location'],
						'trans_inventory'=>$item['quantity_purchased']*-1,
						'trans_unit'=>$item['unit_id']
				);
				// update inventory
				$this->Inventory->insert($inv_data);
				
				// update quantities
				$this->Item_quantities->change_quantity($item['item_id'],
						$item['item_location'],
						$item['unit_id'],
						$item['quantity_purchased']*-1);
			}				
		}
		// delete all items
		$this->db->delete('receivings_items', array('receiving_id' => $receiving_id));
		// delete sale itself
		$this->db->delete('receivings', array('receiving_id' => $receiving_id));
		// execute transaction
		$this->db->trans_complete();
	
		return $this->db->trans_status();
	}

	function get_receiving_items($receiving_id)
	{
		$this->db->from('receivings_items');
		$this->db->where('receiving_id',$receiving_id);
		return $this->db->get();
	}
	
	function get_supplier($receiving_id)
	{
		$this->db->from('receivings');
		$this->db->where('receiving_id',$receiving_id);
		return $this->Supplier->get_info($this->db->get()->row()->supplier_id);
	}
	
	function invoice_number_exists($invoice_number,$receiving_id='')
	{
		$this->db->from('receivings');
		$this->db->where('invoice_number', $invoice_number);
		if (!empty($receiving_id))
		{
			$this->db->where('receiving_id !=', $receiving_id);
		}
		$query=$this->db->get();
		return ($query->num_rows()==1);
	}
	
	//We create a temp table that allows us to do easy report/receiving queries
	function create_receivings_items_temp_table()
	{
		$this->db->query("CREATE TEMPORARY TABLE ".$this->db->dbprefix('receivings_items_temp')."
		(SELECT date(receiving_time) as receiving_date, ".$this->db->dbprefix('receivings_items').".receiving_id, comment, invoice_number, payment_type, employee_id,
		category_name AS category, 
		".$this->db->dbprefix('items').".item_id, ".$this->db->dbprefix('receivings').".supplier_id, quantity_purchased, item_cost_price, item_unit_price,
		discount_percent, (item_unit_price*quantity_purchased-item_unit_price*quantity_purchased*discount_percent/100) as subtotal,
		".$this->db->dbprefix('receivings_items').".line as line, serialnumber, ".$this->db->dbprefix('receivings_items').".description,
		(item_unit_price*quantity_purchased-item_unit_price*quantity_purchased*discount_percent/100) as total,
		(item_unit_price*quantity_purchased-item_unit_price*quantity_purchased*discount_percent/100) - (item_cost_price*quantity_purchased) as profit
		FROM ".$this->db->dbprefix('receivings_items')."
		INNER JOIN ".$this->db->dbprefix('receivings')." ON  ".$this->db->dbprefix('receivings_items').'.receiving_id='.$this->db->dbprefix('receivings').'.receiving_id'."
		INNER JOIN ".$this->db->dbprefix('items')." ON  ".$this->db->dbprefix('receivings_items').'.item_id='.$this->db->dbprefix('items').'.item_id'."
		LEFT OUTER JOIN ".$this->db->dbprefix('items_categories')." ON ". $this->db->dbprefix('items_categories').'.category_id='.$this->db->dbprefix('items').'.category_id'."
		GROUP BY receiving_id, item_id, line)");
	}
   
}
?>
