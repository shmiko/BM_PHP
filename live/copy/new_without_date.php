<?php


require_once CLASSES.'Email.php';

$action = "Delivery Summary Report";
$print_css = "print_orders.css";

require_once ROOT.'.ckw/legacy/admin_global_new.conf';
require_once 'TimeDate.php';
require_once "Ecom/Order.php";
require_once 'fn_send_mail.php';
require_once WEBADMIN_ROOT . 'includes/common_functions.inc.php';	// added by 4mation 20/02/2009
require_once ROOT . '.ckw/legacy/lib/0.2.8/Ecom/allowance.php';

if ($_SESSION['read_weborder']=="1")
{
	include "helpers/hide_selected_client.php";
}


if ($input->post['client_list'])
{
    $input->get['var'] = "";
}

unset($_SESSION['form']);
unset($_SESSION['order_no']);

//update sku_location
//recursive call
function updateSKULocation($sku,$qty)
{
	global $db;

    // gets the location with the largest stock level??
	$sql = "
		SELECT location,stock
		FROM sku_location
		WHERE sku = '$sku'
		AND stock = (SELECT MAX(stock) FROM sku_location WHERE sku = '$sku' GROUP BY sku)
			";
	$result = $db->read($sql);
	$max = $result->fetchRow();
	
	if($max)
	{
	    // if the stock in this location is greater than the qty sold
        // then just reduce this location
		if($max['stock']>=$qty)
		{
			$stockLeft = $max['stock'] - $qty;
			$sql = "
				UPDATE sku_location
				SET stock = '$stockLeft'
				WHERE sku = '$sku'
				AND location = '{$max['location']}'
					";
			$db->execute($sql);

        // otherwise set thsi location to 0
        // reduce qty by amount this location had in it
        // call this function again (will keep doing this till
        // qty completely removed from stock
		} else {
			$sql = "
				UPDATE sku_location
				SET stock = '0'
				WHERE sku = '$sku'
				AND location = '{$max['location']}'
					";
			$db->execute($sql);
			$remainder = $qty - $max['stock'];
			
			updateSKULocation($sku,$remainder);
		}
	}
}

function record_stock_movement($movement_data = array())
{
	global $db;
	
	$date = date("Y-m-d");
	$time = date("H:i:s");
	$stock_current = $movement_data['stock'] + $movement_data['qty'];
	foreach($movement_data as $key=>$value)
	{
		$movement_data[$key] = mysql_real_escape_string($value);
	}
	$sql = "
		INSERT INTO stock_movement (id_order, sku, qty, stock_level_current, stock_level_new, adjustment, action, notes, date, time, orderer, username)
		VALUES ('{$movement_data['id_order']}', '{$movement_data['sku']}', '{$movement_data['qty']}', '{$stock_current}', '{$movement_data['stock']}', '{$movement_data['adjustment']}', '{$movement_data['action']}', '{$movement_data['notes']}', '{$date}', '{$time}', '{$movement_data['orderer']}', '{$movement_data['username']}');
	";
	
	$db->execute($sql);
}

function genStarTrackExport($orders)
{
    include('Export.php');
    
    global $db;
    $data = '';
    foreach($orders as $order)
    {
        $data .= '7';
        $data .= 'A';
        $data .= convert_fixed_width($order['id_order'], 15);
        $data .= convert_fixed_width($order['ship_first_name'] . ' ' . $order['ship_last_name'], 25);
        $data .= convert_fixed_width($order['ship_company'], 25);
        $data .= convert_fixed_width($order['ship_street1'], 25);
        $data .= convert_fixed_width($order['ship_street2'], 25);
        $data .= convert_fixed_width($order['ship_suburb'], 30);
        $data .= convert_fixed_width($order['ship_state'], 25);
        $data .= convert_fixed_width($order['ship_postcode'], 4);
        $data .= convert_fixed_width($order['ship_phone'], 14);
        $data .= 10119939;
        $data .= "\n";
        
        $sql = 'UPDATE weborder SET exported_to = "Star Track" WHERE id_order = "' . $order['id_order'] . '"';
        $db->execute($sql);
    }
    
    $export = new Export('StarTrackOrders', 'rpd', $data);
    $export->exportData();
}

function genTOLLExport($orders)
{
    global $db;
    
    $fileName = 'TollOrders.csv';
    
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header('Content-Description: File Transfer');
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename={$fileName}");
    header("Expires: 0");
    header("Pragma: public");
    
    $fh = @fopen( 'php://output', 'w' );
    
    foreach ( $orders as $order ) 
    {
        /*$sql = "SELECT SUM(wi.qty * s.weight) as weight FROM weborder_item wi
                JOIN product p ON p.id_product = wi.id_product
                JOIN sku s ON s.id_product = p.id_product
                WHERE wi.id_order = {$order['id_order']}";*/
		$sql = "SELECT wi.qty, s.weight as weight,wi.sku, wi.name,wi.qty FROM weborder_item wi
                JOIN product p ON p.id_product = wi.id_product
                JOIN sku s ON s.id_product = p.id_product 
                WHERE s.sku = wi.sku AND wi.id_order = {$order['id_order']}";
        $result = $db->read($sql);
        //$weight = $result->fetchRow();//resultSetToArray()
		$order_items = $result->resultSetToArray();
            
        
		foreach ( $order_items as $item ) 
		{
			$data = array();
			$data[] = 'PRI'; // Carrier ID - Mandatory
			$data[] = date('d/m/Y'); // Despatch Date - Optional
			$data[] = ''; // Connote ID - Optional
			$data[] = '12'; // Service ID - Toll Offpeak - Mandatory
			$data[] = $order['id_order'];//''; // Receiver ID - Optional change to Order ID
			$data[] = substr($order['ship_company'], 0, 40); // Receiver Name - Mandatory
			$data[] = ''; // Address Description - Optional
			$data[] = substr($order['ship_street1'], 0, 40); // Receiver Address 1 - Mandatory
			$data[] = substr($order['ship_street2'], 0, 40); // Receiver Address 2 - Optional
			$data[] = substr($order['ship_suburb'], 0, 40); // Receiver Suburb - Mandatory
			$data[] = substr($order['ship_state'], 0, 3); // Receiver State - Mandatory
			$data[] = substr($order['ship_postcode'], 0, 15); // Receiver Postcode - Mandatory
			$data[] = substr((empty($order['ship_country']) ? 'Australia' : $order['ship_country']), 0, 30); // Receiver Country - Mandatory
			$data[] = substr($order['ship_first_name'] . ' ' . $order['ship_last_name'], 0, 20); // Receiver Contact Name - Mandatory
			$data[] = substr(preg_replace('/[\D]/', '', $order['ship_phone']), 0, 30); // Receiver Contact Phone - Mandatory
			$data[] = $item['sku'];// Instructions Line 1 - Optional  //change to SKU 16
			$data[] = $item['name']; // Instructions Line 2 - Optional  //change to Item Dec
			$data[] = ''; // Instructions Line 3 - Optional
			$data[] = ''; // Connote Reference - Optional
			$data[] = ''; // Item Reference - Optional
			$data[] = ''; // Toll Extra Service - Optional
			$data[] = 'S'; // Who Pays - Mandatory
			$data[] = '205148'; // Sender's ID - Mandatory
			$data[] = ''; // Receiver Account Num - Optional
			$data[] = $item['qty'];//$order['no_of_cartons']; // Total Items - Mandatory
			$data[] = str_replace('.', '', number_format($item['weight'], 2, '', '')); // Total Weight - Mandatory
			$data[] = ''; // Reserved For Future Use - Optional
			$data[] = ''; // Reserved For Future Use - Optional
			$data[] = ''; // Total Cubic Height - Optional
			$data[] = ''; // Total Cubic Length - Optional
			$data[] = ''; // Total Cubic Width - Optional
			$data[] = 'Promotional Items'; // Description Of Goods - Mandatory
			$data[] = ''; // Reserved For Future Use - Optional
			$data[] = ''; // Reserved For Future Use - Optional
			$data[] = ''; // Dangerous Goods Flag - Optional
			$data[] = ''; // Dangerous Goods Class - Optional
			$data[] = ''; // Dangerous Goods Pack Group - Optional
			$data[] = ''; // Dangerous Goods Sub Risk - Optional
			$data[] = ''; // Dangerous Goods UN Code - Optional
			$data[] = ''; // Dangerous Goods In Excepted Quantities - Optional
			$data[] = ''; // Who Pays Duties & Taxes - Optional
			$data[] = ''; // Declared Value of Goods - Optional
			$data[] = ''; // Declared Value Currency - Optional
			$data[] = ''; // Export Type Code - Optional
			
			//$data[] = $order['id_order']; // New Field , order number
			fputcsv($fh, $data);
		}
        
        $sql = 'UPDATE weborder SET exported_to = "TOLL" WHERE id_order = "' . $order['id_order'] . '"';
        $db->execute($sql);                
    }
    
    fclose($fh);
    exit;
}

function ExportLiveOrder($orders)
{
    global $db;
    
    $fileName = 'LiveOrders.csv';
    
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header('Content-Description: File Transfer');
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename={$fileName}");
    header("Expires: 0");
    header("Pragma: public");
    
    $fh = @fopen( 'php://output', 'w' );
	//header
	$data = array();
	$data[] = 'Order No.';
	$data[] = 'Cost Centre';
	$data[] = 'Purchase Order No.';
	$data[] = 'Ordered By';
	$data[] = 'Client';
	$data[] = 'Date';
	$data[] = 'SKU';
	$data[] = 'Item';
	$data[] = 'Size';
	$data[] = 'Colour';
	$data[] = 'Unit';
	$data[] = 'Qty';
	//$data[] = 'nett';
	$data[] = 'Shipping First Name';
	$data[] = 'Shipping Last Name';
	$data[] = 'Shipping Company';
	$data[] = 'Shipping Phone';
	$data[] = 'Shipping Mobile';
	$data[] = 'Shipping Street 1';
	$data[] = 'Shipping Street 2';
	$data[] = 'Shipping Street 3';
	$data[] = 'Shipping Suburb';
	$data[] = 'Shipping State';
	$data[] = 'Shipping Post Code';
	fputcsv($fh, $data);

    
    foreach ( $orders as $order ) 
    {
		/*$sql = "SELECT wi.qty, wi.sku, wi.name,wi.size,wi.colour,wi.unit_size,w.*,c.name as client_name FROM weborder_item wi
				JOIN weborder w ON w.id_order=wi.id_order
				JOIN vvv_client c ON c.id_client = w.id_client
                WHERE wi.id_order = {$order['id_order']}";
        $result = $db->read($sql);
		$order_items = $result->resultSetToArray();*/

		
		/*foreach ( $order_items as $item ) 
		{*/
			$data = array();
			$data[] = $order['id_order'];
			$data[] = "=\"".$order['cost_centre']."\"";
			$data[] = $order['po'];
			$data[] = $order['username'];
			$data[] = $order['client_name'];
			$data[] = $order['date'];
			$data[] = $order['sku'];
			$data[] = $order['name'];
			$data[] = $order['size'];
			$data[] = $order['colour'];
			$data[] = $order['unit_size'];
			$data[] = $order['qty'];
			//$data[] = $order['nett'];
			$data[] = $order['ship_first_name'];			
			$data[] = $order['ship_last_name'];
			$data[] = $order['ship_company'];
			$data[] = $order['ship_phone'];
			$data[] = $order['ship_mobile'];
			$data[] = $order['ship_street1'];
			$data[] = $order['ship_street2'];
			$data[] = $order['ship_street3'];
			$data[] = $order['ship_street1'];
			$data[] = $order['ship_suburb'];
			$data[] = $order['ship_state'];
			$data[] = $order['ship_postcode'];
			fputcsv($fh, $data);
		/*}*/
    }
    
    fclose($fh);
    exit;
}


function ExportDailyOrder($orders)
{
    global $db;
    
    $fileName = 'DailyOrders.csv';
    
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header('Content-Description: File Transfer');
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename={$fileName}");
    header("Expires: 0");
    header("Pragma: public");
    
    $fh = @fopen( 'php://output', 'w' );
	//header
	$data = array();
	$data[] = 'Order No.';
	$data[] = 'Cost Centre';
	$data[] = 'Purchase Order No.';
	$data[] = 'Ordered By';
	$data[] = 'Client';
	$data[] = 'Date';
	$data[] = 'SKU';
	$data[] = 'Item';
	$data[] = 'Size';
	$data[] = 'Colour';
	$data[] = 'Unit';
	$data[] = 'Qty';
	$data[] = 'Unit Price';
	//$data[] = 'Unit Price2';

	$data[] = 'First Name';
	$data[] = 'Last Name';
	$data[] = 'Company';
	$data[] = 'Phone';
	$data[] = 'Mobile';
	$data[] = 'Street 1';
	$data[] = 'Street 2';
	$data[] = 'Street 3';
	$data[] = 'Suburb';
	$data[] = 'State';
	$data[] = 'Post Code';
	fputcsv($fh, $data);

    
    foreach ( $orders as $order ) 
    {
		/*$sql = "SELECT wi.qty, wi.sku, wi.name,wi.size,wi.colour,wi.unit_size,w.*,c.name as client_name FROM weborder_item wi
				JOIN weborder w ON w.id_order=wi.id_order
				JOIN vvv_client c ON c.id_client = w.id_client
                WHERE wi.id_order = {$order['id_order']}";
        $result = $db->read($sql);
		$order_items = $result->resultSetToArray();*/

		
		/*foreach ( $order_items as $item ) 
		{*/
			$data = array();
			$data[] = $order['id_order'];
			$data[] = "=\"".$order['cost_centre']."\"";
			$data[] = $order['po'];
			$data[] = $order['username'];
			$data[] = $order['client_name'];
			$data[] = $order['date'];
			$data[] = $order['sku'];
			$data[] = $order['name'];
			$data[] = $order['size'];
			$data[] = $order['colour'];
			$data[] = $order['unit_size'];
			$data[] = $order['qty'];
			$data[] = $order['unit_price'];
			//$data[] = $order['unit_price'];
			$data[] = $order['ship_first_name'];			
			$data[] = $order['ship_last_name'];
			$data[] = $order['ship_company'];
			$data[] = $order['ship_phone'];
			$data[] = $order['ship_mobile'];
			$data[] = $order['ship_street1'];
			$data[] = $order['ship_street2'];
			$data[] = $order['ship_street3'];
			$data[] = $order['ship_suburb'];
			$data[] = $order['ship_state'];
			$data[] = $order['ship_postcode'];
			fputcsv($fh, $data);
		/*}*/
    }
    
    fclose($fh);
    exit;
}

//WARREN PLATYBANKEXPORT
function genPlatyBankExport($orders)
{
    global $db;
    
    $fileName = 'PlatyBankOrders.csv';
    
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header('Content-Description: File Transfer');
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename={$fileName}");
    header("Expires: 0");
    header("Pragma: public");
    
    $fh = @fopen( 'php://output', 'w' );
    
    foreach ( $orders as $order ) 
    {
        
		//Jarred 4mation 31/8/12 Added qty select and removed weight * qty
        //$sql = "SELECT wi.qty, s.weight as weight FROM weborder_item wi
		
		//Jarred 4mation 4/9/12 Put the Qty back at client's request
		$sql = "SELECT wi.qty, SUM(wi.qty * s.weight) as weight FROM weborder_item wi
                JOIN product p ON p.id_product = wi.id_product
                JOIN sku s ON s.id_product = p.id_product
                WHERE wi.id_order = {$order['id_order']}";
        $result = $db->read($sql);
        $weight = $result->fetchRow();

        $data = array();
        $data[] = 'PRI'; // Carrier ID - Mandatory
        $data[] = date('d/m/Y'); // Dispatch Date - Optional
        $data[] = ''; // Connote ID - Optional
        $data[] = '12'; // Service ID - Toll Offpeak - Mandatory
        $data[] = ''; // Receiver ID - Optional
        $data[] = substr($order['ship_company'], 0, 40); // Receiver Name - Mandatory
        $data[] = ''; // Address Description - Optional
        $data[] = substr($order['ship_street1'], 0, 40); // Receiver Address 1 - Mandatory
        $data[] = substr($order['ship_street2'], 0, 40); // Receiver Address 2 - Optional
        $data[] = substr($order['ship_suburb'], 0, 40); // Receiver Suburb - Mandatory
        $data[] = substr($order['ship_state'], 0, 3); // Receiver State - Mandatory
        $data[] = substr($order['ship_postcode'], 0, 15); // Receiver Postcode - Mandatory
        $data[] = 'Australia'; // Receiver Country - Mandatory
        $data[] = substr($order['ship_first_name'] . ' ' . $order['ship_last_name'], 0, 20); // Receiver Contact Name - Mandatory
        $data[] = substr(preg_replace('/[\D]/', '', $order['ship_phone']), 0, 30); // Receiver Contact Phone - Mandatory
        $data[] = $order['id_order']; // Brand Manager Order Number
        $data[] = ''; // Instructions Line 2 - Optional
        $data[] = ''; // Instructions Line 3 - Optional
        $data[] = substr($order['cost_centre'], 0, 30); // Connote Reference - Optional
        $data[] = $order['po']; // Item Reference - Optional
        $data[] = ''; // Toll Extra Service - Optional
        $data[] = 'S'; // Who Pays - Mandatory
        $data[] = '203952'; // Sender's ID - Mandatory
        $data[] = ''; // Receiver Account Num - Optional
		//Jarred 4mation 31/8/12 Replaced with qty ordered
        //$data[] = $order['no_of_cartons']; // Total Items - Mandatory 
        $data[] = $weight['qty']; // Total Items - Mandatory 
		//End Jarred
        $data[] = number_format($weight['weight'], 2, '', ''); // Total Weight - Mandatory
        $data[] = ''; // Reserved For Future Use - Optional
        $data[] = ''; // Reserved For Future Use - Optional
        $data[] = '44'; // Total Cubic Height - Optional
        $data[] = '32'; // Total Cubic Length - Optional
        $data[] = '40'; // Total Cubic Width - Optional
        $data[] = 'Platybanks'; // Description Of Goods - Mandatory
        $data[] = ''; // Reserved For Future Use - Optional
        $data[] = ''; // Reserved For Future Use - Optional
        $data[] = ''; // Dangerous Goods Flag - Optional
        $data[] = ''; // Dangerous Goods Class - Optional
        $data[] = ''; // Dangerous Goods Pack Group - Optional
        $data[] = ''; // Dangerous Goods Sub Risk - Optional
        $data[] = ''; // Dangerous Goods UN Code - Optional
        $data[] = ''; // Dangerous Goods In Excepted Quantities - Optional
        $data[] = ''; // Who Pays Duties & Taxes - Optional
        $data[] = ''; // Declared Value of Goods - Optional
        $data[] = ''; // Declared Value Currency - Optional
        $data[] = ''; // Export Type Code - Optional
		$data[] = $order['id_order']; // New Field , Order Number
         
    	fputcsv($fh, $data);
		
        if (in_array($_SERVER['REMOTE_ADDR'], array('120.151.150.174','150.101.192.90'))) {
			//Jarred 4mation 31/8/12 Make sure it doesn't update the database when debugging the CSVs
		} else {
			$sql = 'UPDATE weborder SET exported_to = "PlatyBank" WHERE id_order = "' . $order['id_order'] . '"';
			$db->execute($sql);        
		}			
    }
    
    fclose($fh);
    exit;
}

function convert_fixed_width($text, $width)
{
    $text = substr($text, 0, $width);
    $length = strlen($text);
    return $text . str_repeat(' ', $width - $length);
}

//add item to back order
//Tuesday, June 05, 2007
function addToBackOrder($boOrderID,$boProdID,$boSKU,$boQty,$boProdName,$boSize,$boColour,$boUnitSize,$boPrice,$boTotal)
{
	global $db;

	$sql = "
		INSERT INTO weborder_item_bo (bo_id,bo_prod_id,sku,qty,name,size,colour,unit_size,price,total)
		VALUES ('$boOrderID','$boProdID','$boSKU','$boQty','$boProdName','$boSize','$boColour','$boUnitSize','$boPrice','$boTotal')
			";
	$db->execute($sql);

}

function addToBackOrder2($boOrderID)
{
	global $db;

	//calculate total & tax
	$sql = "
		SELECT SUM(total) as `theTotal`
		FROM `weborder_item_bo` WHERE bo_id = '$boOrderID' GROUP BY bo_id
			";
	$result = $db->read($sql);
	$row = $result->fetchRow();
	$taxIncluded =  ($row['theTotal']/1.1)*0.1;

	$sql = "
		INSERT INTO weborder_bo (bo_id,total,tax) VALUES ('$boOrderID',{$row['theTotal']},$taxIncluded)
			";
	$db->execute($sql);
}

//delete from weborder_item
function deleleteWeborderItem($idOrder, $sku)
{
	global $db;

	$sql = "
		DELETE FROM weborder_item
		WHERE id_order = '$idOrder'
		AND sku = '$sku'
		";

	$db->execute($sql);

	$sql_booking = "
		SELECT *
		FROM   `sku_booking`
		WHERE  `id_order` = '$idOrder'
	";

	$res = $db->read($sql);

	if($res->getNumRows() > 0)
	{
		$booking_delete = "
		DELETE FROM   `sku_booking`
		WHERE  `id_order` = '$idOrder';
		";

		$db->execute($booking_delete);
	}
}

//update weborder
function updateWeborder($idOrder)
{
	global $db;

	$sql = "
		SELECT SUM(total) as `theTotal`
		FROM `weborder_item` WHERE id_order = '$idOrder' GROUP BY id_order
			";
	$result = $db->read($sql);
	$detail = $result->fetchRow();

	if(!empty($detail))
	{
		$taxIncluded =  ($detail['theTotal']/1.1)*0.1;
		$sql = "
			UPDATE weborder SET total = {$detail['theTotal']}, tax = $taxIncluded
			WHERE id_order = '$idOrder'
			";

	}
	else	//no weborder_item left
	{
		$sql = "
			UPDATE weborder SET total = 0, tax = 0
			WHERE id_order = '$idOrder'
			";
	}
	$result = $db->execute($sql);
}

function getBackOrderItems($idOrder)
{
	global $db;
	$sql = "
		SELECT wi.id_product,wi.sku,wi.qty,wi.name,wi.size,wi.colour,wi.unit_size,wi.price,wi.total
		FROM sku s, weborder_item wi
		WHERE wi.sku = s.sku
		AND s.stock = 0
		AND wi.id_order = '$idOrder'
		";
	$result = $db->read($sql);
	return $result->resultSetToArray();
}
function checkBO($idOrder)
{
	global $db;
	$isBo = false;
	/*$sql = "
		SELECT stock AS 'total'
		FROM sku s, weborder_item wi
		WHERE wi.sku = s.sku
		AND wi.id_order = '$idOrder'
		GROUP BY s.sku
		";*/
	$sql = "
		SELECT stock, ordered,wi.qty
		FROM sku s, weborder_item wi
		WHERE wi.sku = s.sku
		AND wi.id_order = '$idOrder'
		GROUP BY s.sku
		";
	$result = $db->read($sql);
	$itemList = $result->resultSetToArray();

	if($itemList)
	{
		foreach($itemList as $item)
		{
			//if($item['total'] <= 0)
			//if(($item['stock']-$item['ordered']) < 0 &&$item['stock']>0)
			if($item['stock'] < $item['qty'])
			{
				$isBo = true;
				break;
			}
		}
	}
	return $isBo;
}

//use this function instead of the one in Order.php
function buildOrderTable($items, $order, $boNote='')
{
	global $db, $client_id;

    $usql = "
        SELECT l.al_global,v.portal_payment
        FROM   login l
		JOIN vvv_client v ON v.id_client = l.id_client
        WHERE  l.username = '{$order->vals['username']}'
    ";
    $uresult = $db->read($usql);
    $row = $uresult->fetchRow();
    $al = $row['al_global'];
	$portal_payment = $row['portal_payment'];

	$table = "<div id=\"cart\">\n";

	if($boNote)
		$table .= "<span style='color:red'>$boNote</span>";

	$table .= "<table border='0' cellpadding='0' cellspacing='0'>";

	if($portal_payment==1) {
		$table .= "
			<tr>
				<th class='col1'>SKU</th>
				<th class='col2'>Item</th>
				<th class='col3'>Size</th>
				<th class='col4'>Colour</th>
				<th class='col5'>Location</th>
				<th class='col6'>Unit</th>
				<th class='qty'>Qty</th>
				<th class='currency'>Points</th>
				<th class='currency'>Total</th>
			</tr>
		";
	}
	else
	{
		$table .= "
			<tr>
				<th class='col1'>SKU</th>
				<th class='col2'>Item</th>
				<th class='col3'>Size</th>
				<th class='col4'>Colour</th>
				<th class='col5'>Location</th>
				<th class='col6'>Unit</th>
				<th class='qty'>Qty</th>
				<th class='currency'>Price</th>
				<th class='currency'>Total</th>
			</tr>
		";
	}

	//debug($items, '$items');

    $nett_total = 0;
	if($items)
	{
		foreach ($items as $item)
		{
			$sql = "
				SELECT stock,ordered
				FROM sku
				WHERE sku = '{$item['sku']}'
					";
			$result = $db->read($sql);
			$row = $result->fetchRow();

			//get location
			$sql = "
				SELECT location
				FROM sku_location
				WHERE sku = '{$item['sku']}'
					";
			$result = $db->read($sql);
			$locationList = $result->resultSetToArray();
			if($locationList)
			{
				foreach($locationList as $l)
				{
					$location .= $l['location']."<br />";
				}
			}
			else
				$location = 'N/A';

            $tpl = "";
            if ($item['print_template'] > 0) {
                $tpl = " <a href='/webadmin/print2/templates/{$item['print_template']}'>template</a>";
            }

			// Display extra info for SKU Booking Products only
			// 4mation mod 06-03-2009
			//debug($item, '$item');

			if(is_array($item['sku_booking']))
			{

				$item['sku_booking']['return_date'] = date('d/m/Y', strtotime($item['sku_booking']['return_date']));
				$item['sku_booking']['pickup_date'] = date('d/m/Y', strtotime($item['sku_booking']['pickup_date']));

				$item['name'] = $item['name'] . '<br/>' . $item['sku_booking']['pickup_date'] . ' - ' . $item['sku_booking']['return_date'].'<br/>';

				$item['name'] .= '<b>Pickup Contact Name: </b><br/>'.$item['sku_booking']['pickup_contact_name'].'<br/>';
				$item['name'] .= '<b>Pickup Address: </b><br/>'.$item['sku_booking']['pickup_address'].'<br/>';
				$item['name'] .= '<b>Pickup Time: </b><br/>'.$item['sku_booking']['pickup_time'].'<br/>';
			}
			//var_dump(htmlentities($item['name']));exit;
			//string(43) "TP-0033-Men�s Navy/Green/White Racing Shirt" 
			//$item_name = str_replace("�","'",htmlentities($item['name']));
			$item_name = str_replace("�","'",$item['name']);

			if($row['stock'] >= (int)$item['qty']) //0
			{
				if($portal_payment==1) {
					$table .= "
						<tr>
							<td class='col1'>{$item['sku']}$tpl</td>
							<td class='col2'>".$item_name."</td>
							<td class='col3'>{$item['size']}</td>
							<td class='col4'>{$item['colour']}</td>
							<td>$location</td>
							<td class='col6'>{$item['unit_size']}</td>
							<td class='qty'>{$item['qty']}</td>
							<td class='currency'>{$item['points']}</td>
							<td class='currency'>{$item['total']}</td>
						</tr>
					";
				}
				else
				{				
					$table .= "
						<tr>
							<td class='col1'>{$item['sku']}$tpl</td>
							<td class='col2'>".$item_name."</td>
							<td class='col3'>{$item['size']}</td>
							<td class='col4'>{$item['colour']}</td>
							<td>$location</td>
							<td class='col6'>{$item['unit_size']}</td>
							<td class='qty'>{$item['qty']}</td>
							<td class='currency'>{$item['price']}</td>
							<td class='currency'>{$item['total']}</td>
						</tr>
					";
				}
			}
			else
			{
				if($portal_payment==1) {
					$table .= "
						<tr class='bo'>
							<td class='col1'>{$item['sku']}$tpl</td>
							<td class='col2'>{$item_name}</td>
							<td class='col3'>{$item['size']}</td>
							<td class='col4'>{$item['colour']}</td>
							<td>$location</td>
							<td class='col6'>{$item['unit_size']}</td>
							<td class='qty'>{$item['qty']}</td>
							<td class='currency'>{$item['points']}</td>
							<td class='currency'>{$item['total']}</td>
						</tr>
					";
				}
				else
				{
					$table .= "
						<tr class='bo'>
							<td class='col1'>{$item['sku']}$tpl</td>
							<td class='col2'>{$item_name}</td>
							<td class='col3'>{$item['size']}</td>
							<td class='col4'>{$item['colour']}</td>
							<td>$location</td>
							<td class='col6'>{$item['unit_size']}</td>
							<td class='qty'>{$item['qty']}</td>
							<td class='currency'>{$item['price']}</td>
							<td class='currency'>{$item['total']}</td>
						</tr>
					";
				}
			}
			$location = "";	//reset variable
            
            if ($order->vals['nett'] == '')
            {
                $nett_total += $item['total'];
            }
		}
	}

    if ($currency == 'AUD') {
        $currency = "";
    }

	// cal portal freight 
	$surcharge_tmp = 0.00;
	if (!empty($order->vals['2_percent_surcharge']))
	{
		$surcharge_tmp += round($order->vals['2_percent_surcharge'],2);
	}

	if (!empty($order->vals['surcharge']))
	{
		$surcharge_tmp += round($order->vals['surcharge'],2);
	}

	$freight_tmp = 0.00;

	$total_t = round($order->vals['total'],2);
	$nett_t = round($order->vals['nett'],2);
	$tax_t = round($order->vals['tax'],2);
	$freight_tmp = $total_t - $nett_t - $tax_t;

	$freight_tmp = round($freight_tmp,2) - round($surcharge_tmp,2);

    if (in_array($order->vals['id_client'],array(3556,4239)))
    {
        $table .= "<tr id='total-freight'><td colspan='7'>Shipping & Processing Fee inc-GST</td><td class='currency'>$</td><td class='currency'>".$order->vals['shipping_processing_fee']."</td></tr>";//Currency::decimalise($freight_tmp)
        $table .= "<tr id='total-freight'><td colspan='7'>2% Credit Card Surcharge inc-GST</td><td class='currency'>$</td><td class='currency'>{$order->vals['2_percent_surcharge']}</td></tr>";
        $table .= "<tr id='total-freight'><td colspan='7'>Total inc-GST</td><td class='currency'>$</td><td class='currency'>{$order->vals['total']}</td></tr>";
    }
    elseif ($order->vals['id_order'] > 28734)
    {
		if($portal_payment==1) {
			$table .= "<tr id='total-freight'><td colspan='7'>Nett</td><td class='currency'></td><td class='currency'>" . round($order->vals['nett']) . "</td></tr>";

			$table .= "<tr id='total-freight'><td colspan='7'>Shipping & Processing Fee</td><td class='currency'></td><td class='currency'>" . round($order->vals['freight']) . "</td></tr>";//$order->vals['freight']

			$table .= "<tr id='total-freight'><td colspan='7'>Sub-Total</td><td class='currency'></td><td class='currency'>" . round(($order->vals['nett'] + $order->vals['freight'])) . "</td></tr>";		//$order->vals['freight']

			$table .= "<tr id='total-freight'><td colspan='7'>Total</td><td class='currency'></td><td class='currency'>" . round($order->vals['total']) . "</td></tr>";
		}
		else
		{
			$table .= "<tr id='total-freight'><td colspan='7'>Nett (Ex GST)</td><td class='currency'>".$currency."$</td><td class='currency'>" . ($order->vals['nett'] == '' ? Currency::decimalise($nett_total) : $order->vals['nett']) . "</td></tr>";

			if (in_array($order->vals['id_client'],array(50))) // hardcode freight is $20.00
			{
				$table .= "<tr id='total-freight'><td colspan='7'>Shipping & Processing Fee (Ex GST)</td><td class='currency'>$</td><td class='currency'>".Currency::decimalise($freight_tmp)."</td></tr>";
			}
			else
			{
				$table .= "<tr id='total-freight'><td colspan='7'>Shipping & Processing Fee (Ex GST)</td><td class='currency'>$</td><td class='currency'>" . Currency::decimalise($freight_tmp) . "</td></tr>";//$order->vals['freight']
			}
			$table .= "<tr id='total-freight'><td colspan='7'>Sub-Total (Ex GST)</td><td class='currency'>".$currency."$</td><td class='currency'>" . Currency::decimalise(($order->vals['nett'] + $freight_tmp)) . "</td></tr>";		//$order->vals['freight']

			if ($order->vals['payment_type'] == 'card')
			{
				$table .= "<tr id='total-freight'><td colspan='7'>Credit Card Surcharge (Ex GST)</td><td class='currency'>$</td><td class='currency'>" . Currency::decimalise($order->vals['surcharge']) . "</td></tr>";    
			}
			$table .= "<tr id='total-freight'><td colspan='7'>GST</td><td class='currency'>".$currency."$</td><td class='currency'>" . Currency::decimalise($order->vals['tax']) . "</td></tr>";
			$table .= "<tr id='total-freight'><td colspan='7'>Total</td><td class='currency'>$</td><td class='currency'>" . Currency::decimalise($order->vals['total']) . "</td></tr>";
		}
    }
    else
    {
        $table .= "<tr id='total-freight'><td colspan='7'>Total (Ex GST)</td><td class='currency'>".$currency."$</td><td class='currency'>{$order->vals['total']}</td></tr>";
    }

	
    if ($al) {
        $table .= "<tr><td colspan='7'>Freight</td><td class='currency'>".$currency."$</td><td class='currency'>{$order->vals['freight']}</td></tr>";
        $gtotal = Currency::decimalise($order->vals['freight'] + $order->vals['total']);
        $table .= "<tr id='total-freight'><td colspan='7'>Total</td><td class='currency'>".$currency."$</td><td class='currency'>$gtotal</td></tr>";
    }
	/*$table .= "<tr id='tax'><td colspan='7'>TaxIncluded</td><td class='currency'>$tax</td></tr>";*/
	$table .= "</table></div>";
	return $table;
}

function re_send_invocie($order_id)
{
	global $db;
	$order =& DB::record($db, 'weborder');
    $order->fetch($order_id);
	

	$email = new Email;

	$to = $order->vals['bill_email'];
	if ($to !="")
	{
		$client_name = "";
		$sql = "
		SELECT name
		FROM vvv_client
		WHERE id_client = '{$order->vals['id_client']}'
		";
		$result = $db->read($sql);
		
		if ($row = $result->fetchRow())
		{
			$client_name = $row['name'];
		}

		require_once 'order_print_versions_ad.php';

		if ($order->vals['payment_type']=="card")
		{
			$confirmContent = getCCEmailContent_ad($order_id);
		}
		else if ($order->vals['payment_type'] =='account')
		{
			$confirmContent = getEmailContent_admin($order_id);
		}

		$subject = "BM Web Order: ".$order_id." - ".$client_name;
		$email->setTo($to);
		//$email->setTo('liangzhilong@hotmail.com');
		//$email->setTo('zhilong.liang@vavavoom.com');
		$email->setFrom('BluestarPromote.Support@bluestargroup.com.au', 'Brand Manager');
		$email->setSubject($subject);
		$email->setHtml($confirmContent);
		$email->send();
		return true;
	}
	else
	{
		return false;
	}
}

//add dispatch all button for Jason user
function dispatchAll($order_ids) {
	if($order_ids) {
	
		foreach ($order_ids as $tmp_order_no)
		{
			if(substr($tmp_order_no,0,2)=="AD"){
				//$adhoc_string .= "'".substr($p,2)."',";
			}else{
				//$weborder_string .= "'$p',";
				//tmp_order_no

				$date = date("Y-m-d");
				$subject= "Personal purchase order $tmp_order_no";
				$con_note = 'Bulk Dispatch';
				$freight = 0;
				$carrier = 'FRF';
				global $db;	
					// VAVAVOOM carrier
						$freightTax = $freight*10/100;
						$totalFreight = $freightTax + $freight;
						$order =& DB::record($db, 'weborder');
						
						$order->fetch($tmp_order_no);
						$order->set('status', 'processed');
						$order->set('freight', $freight);
						$order->set('tax_freight', $freightTax);
						$order->set('total_freight', $totalFreight);
						$order->set('con_note', $con_note);
						$order->set('carrier', $carrier);
						$order->set('logistic_comment', '');
						$order->set('dispatch_date', $date);		

						if((empty($order->vals['bo_order']) && empty($order->vals['job_id']))||($order->vals['bo_order']=='0' && $order->vals['job_id']=='0'))
						{
							// update stock location's quantity
							// get order items
							$sql = "
								SELECT `sku`, `qty`
								FROM   `weborder_item`
								WHERE  `id_order` = '$tmp_order_no'
							";
							
							$result = $db->read($sql);
							
							while ($row = $result->fetchRow())
							{
								if (! empty($row['sku']))
								{
									// reduce stock levels
									$sku =& DB::record($db, 'sku');
									$sku->fetch($row['sku']);
									$sku->vals['stock'] -= $row['qty'];
									$sku->vals['ordered'] -= $row['qty'];
									
									if ($sku->vals['ordered'] < 0)
									{
										$sku->vals['ordered'] = 0;
									}
									
									if (!$sku->vals['replacement_value'])
									{
										$sku->vals['replacement_value'] = 'NULL';
									}
									
									if($sku->save()){
									}else{
										mail('Zhilong.Liang@bluestargroup.com.au','Error Saving SKU',print_r($sku,1));
									}
									
									// make adjustments to the stock movement log
									$orderer = $order->vals['bill_first_name']." ".$order->vals['bill_last_name'];
									$movement_data = array(
										'stock' => $sku->vals['stock'],
										'qty' => $row['qty'],
										'id_order' => $tmp_order_no,
										'sku' => $row['sku'],
										'adjustment' => 'remove',
										'action' => 'order',
										'notes' => 'Dispatched Order [Date: '.$date.']',
										'orderer' => $orderer,
										'username' => $order->vals['username']
									);
									echo record_stock_movement($movement_data);
																
									$adjust_pending = Allowance::adjust_pending($order->val['username'], $order->val['nett'], '-');
									
									//update sku_location
									updateSKULocation($row['sku'], $row['qty']);
								}
							}
						}
						
						$sql = "
							UPDATE jobs j
								INNER JOIN weborder o ON o.job_id = j.id
							SET 
								j.con_note = '{$con_note}', 
								j.status = 'Complete'
							WHERE o.id_order = '{$tmp_order_no}'
						";
						$db->execute($sql);

						
						// Email: Personal Purchase Dispatched
						//send email to Zora
						if($order->vals['cost_centre'] == 'Personal Purchase')
						{
							$link = "/common/images/logo_print.gif";
							$sql = "
								SELECT *
								FROM vvv_client
								WHERE id_client = {$order->vals['id_client']}
									";
							$result = $db->read($sql);
							$clientDetail = $result->fetchRow();

							$emailContent = "
								<div>
									<img src='$link' width='223' height='34' />
								</div>
								<p>
									A personal purchase has been dispatched. <br />
									Order number: $tmp_order_no <br />
									Client: {$clientDetail['name']} <br />
									Freight cost (inc. GST): \${$totalFreight}. <br />
									Con Note: {$con_note} <br /><br />
									Regards,									
								</p>
							";
							send_mail("administration@vavavoom.com.au", "accounts@vavavoom.com.au", $subject, $emailContent, FALSE);

							$email = new Email;
							$email->setGroup('PPDS');
							$email->setSubject($subject);
							$email->setHtml($emailContent);
							//$email->send();

						}

						$subject = "BrandManager.biz Order Dispatch Confirmation";
						
						if(in_array($order->vals['id_client'], array(3782)))
						{
							$logos = "<a href='http://www.vavavoom.com'><img src='https://secure.brandmanager.biz/email/images/AMF.PNG' alt='Vavavoom' title='Go to main website'></a>";
						}
						else
						{
							$logos = "<a href='http://www.vavavoom.com'><img src='https://secure.brandmanager.biz/email/images/logoVvm.jpg' alt='Vavavoom' title='Go to main website'></a>";
						}		
						
						$confirmContent = "<html xmlns='http://www.w3.org/1999/xhtml'>
  <head>
    <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'/>
    <title>Autoresponder</title>
    <!-- Hotmail ignores some valid styling, so we have to add this -->
  </head>
  <body leftmargin='0' topmargin='0' marginwidth='0' marginheight='0' style='width: 100%;height: 100%;background-color: #efefef;margin: 0;padding: 0;-webkit-font-smoothing: antialiased'>
<!-- Wrapper -->
<table width='100%' height='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='100%' height='100%' valign='top'>	

		<!-- Main wrapper -->
		<table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td>
					<!-- Navigation -->
					<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor=''><tr><td width='20'/>
							<td>	
									<table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><img src='https://secure.brandmanager.biz/email/bsp_images/track.jpg' alt=''/></tr></table></td>
						</tr></table>
						</td>
						</tr>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='580' style='line-height: 1px; height: 40px;'>
								
							</td>
						</tr></table><table width='578' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor='#ffffff'><tr><td width='20'/>
							
							<td width='538'>
							</td>
							<td width='20'/>
						</tr><tr><td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr><tr><td width='40' height='15'/>
							<td width='518' height='15'/>
							<td width='20' height='15'/>
						</tr><tr><td width='40'/>
							
							<td width='518' valign='top' style='font-size: 14px; color: #575757; font-weight: normal; text-align: left; font-family: Helvetica, Arial, sans-serif; line-height: 20px;'>
								Hi {$order->vals['bill_first_name']}, <br/><br/>
								
								Your BrandManager order is on its way! <br/><br/>
								
								We've picked and packed your order <span style='text-decoration: none; color: #72CCD2; font-weight: bold;'>#{$order->vals['id_order']}</span> of the following products: <br/><br/>
							";
						//get the order detail
						$sql = "
							SELECT *
							FROM   weborder_item
							WHERE  id_order = '$tmp_order_no'
						";
						$result = $db->read($sql);
						$items = $result->resultSetToArray();
						if($items)
						{
							//$confirmContent .= "<table border='0' cellspacing='5' cellpadding='0'>";
							foreach($items as $item)
							{
								if ($item['qty']>0)
								{
									$confirmContent .= "<div style='text-decoration: none; color: #72CCD2; font-weight: bold;'>{$item['qty']}   {$item['name']}   {$item['size']}   {$item['colour']}</div>";
								}
							}
							//$confirmContent .= "</table>";
							$confirmContent .= "<br/>";
						}
						$confirmContent .= "These items have been dispatched for delivery to:
						<br/><br/><div style='color: #494949;font-weight: bold;font-style: oblique;'>{$order->vals['ship_company']} <br/>
							{$order->vals['ship_first_name']} {$order->vals['ship_last_name']} <br/>
					";
						
						if($order->vals['ship_street1'])
							$confirmContent .= $order->vals['ship_street1']."<br />";
						if($order->vals['ship_street2'])
							$confirmContent .= $order->vals['ship_street2']."<br />";
						if($order->vals['ship_street3'])
							$confirmContent .= $order->vals['ship_street3']."<br />";
						if($order->vals['ship_suburb'])
							$confirmContent .= $order->vals['ship_suburb']."<br />";
						$confirmContent .= "
						{$order->vals['ship_state']} {$order->vals['ship_postcode']} <br/>
						{$order->vals['ship_country']}
						</div>
						<br/><br/>For your reference, your package is in the capable hands of our courier partner {$order->vals['carrier']}, under consignment number {$order->vals['con_note']}.<br/><br/>
						If you have any further enquiries or comments, please contact customer service.
						<br/><br/>
                        ";
							if ($_SESSION['loginType'] == 'hc') {
								$confirmContent .= "
    						    Thanks from the team at Propeller Marketing<br/><br/>
							";
							} else {
								$confirmContent .= "All the best,    						    
							";
							}
							$confirmContent .="<br/><br/></td>
							<td width='20'/>
						</tr><tr><td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr></table>
						</td>
						</tr>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='580' style='line-height: 1px; height: 40px;'>
								
							</td>
						</tr></table><table width='578' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor=''><tr><td width='10' height='15'/>
							<td width='538' height='15'/>
							<td width='20' height='15'/>
						</tr><tr><td width='20'/>
							<td width='528'><table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><img src='https://secure.brandmanager.biz/email/bsp_images/footer.jpg' alt=''></tr></table>
							</td>
							<td width='20'/>
						</tr><tr><td width='10' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr></table>
						</td>
						</tr></table><!-- End Footer --></td>
			</tr></table><!-- End Main wrapper --></td>
	</tr></table><!-- End Wrapper --><!-- Done --></body>
</html>";

							$email = new Email;
							
							// commbank iShop
							if ($order->vals['id_client'] == 3556)
							{
								$to = $order->vals['bill_email'];
							}
							else
							{
								// Hack for TyrePlus or for any username that isn't an email address
								$to = strpos($vals['username'], '@') !== false ? $order->vals['bill_email'] : $order->vals['bill_email'];
							}
							$email->setTo($order->vals['bill_email']);
							$email->setFrom('BluestarPromote.Support@bluestargroup.com.au', 'Brand Manager');
							$email->setSubject($subject);
							$email->setHtml($confirmContent);
							
							if ('vic' != $order->vals['shipping_from'])
							{
								if ($order->vals['id_client'] != 6449)
								{
									$email->send();
								}
							}
					
				

				if (!$order->vals['client_freight_flag']) {
					$order->vals['client_freight_flag'] = '0';
				}
				if (!$order->vals['num_approval_contact']) {
					$order->vals['num_approval_contact'] = 'NULL';
				}
				
				
				$order->vals['bo_order'] = 0;

				if(!$order->save()){
					echo "AN ERROR OCCURRED WHILST DISPATCHING THIS ORDER.<br />
						  DO NOT ATTEMPT TO DISPATCH THIS ORDER AGAIN.<br />
						  PLEASE Contact Admin<br />";
					die();
				}
			}
		}
	}
}
//add dispatch special ITEMS by SKU, etc MCAFTP
//function dispatchAll_Spe($order_ids,$cartons,$con_note,$carrier_name,$freight) {
function dispatchAll_Spe($order_ids,$con_note,$carrier,$freight,$SKU) {
	
	if($order_ids) {
		ini_set('max_execution_time', 600);
	
		foreach ($order_ids as $tmp_order_no)
		{
			if(substr($tmp_order_no,0,2)=="AD"){
				//$adhoc_string .= "'".substr($p,2)."',";
			}else{
				//$weborder_string .= "'$p',";
				//tmp_order_no

				$date = date("Y-m-d");
				$subject= "Personal purchase order $tmp_order_no";
				//$con_note = 'Bulk Dispatch';
				//$freight = 0;
				//$carrier = 'FRF';
				global $db;	
					// VAVAVOOM carrier
						$freightTax = $freight*10/100;
						$totalFreight = $freightTax + $freight;
						$order =& DB::record($db, 'weborder');
						
						$order->fetch($tmp_order_no);
						$order->set('status', 'processed');
						$order->set('freight', $freight);
						$order->set('tax_freight', $freightTax);
						$order->set('total_freight', $totalFreight);
						$order->set('con_note', $con_note);
						$order->set('carrier', $carrier);
						$order->set('logistic_comment', '');
						$order->set('dispatch_date', $date);		
						
						$send = false;
						
						if((empty($order->vals['bo_order']) && empty($order->vals['job_id']))||($order->vals['bo_order']=='0' && $order->vals['job_id']=='0'))
						{
							// update stock location's quantity
							// get order items
							$sql = "
								SELECT `sku`, `qty`
								FROM   `weborder_item`
								WHERE  `id_order` = '$tmp_order_no'
							";
							
							$result = $db->read($sql);
							$send = true;
							while ($row = $result->fetchRow())
							{
								if (! empty($row['sku']))
								{
									if($row['sku']==$SKU) {
									}
									else
									{
										$send = false;
									}
								}
								else
								{
									$send = false;
								}
							}
							$result = $db->read($sql);

							while (($row = $result->fetchRow())&&$send)
							{
								if (! empty($row['sku']))
								{
									// reduce stock levels
									$sku =& DB::record($db, 'sku');
									$sku->fetch($row['sku']);
									$sku->vals['stock'] -= $row['qty'];
									$sku->vals['ordered'] -= $row['qty'];
									
									if ($sku->vals['ordered'] < 0)
									{
										$sku->vals['ordered'] = 0;
									}
									
									if (!$sku->vals['replacement_value'])
									{
										$sku->vals['replacement_value'] = 'NULL';
									}
									
									if($sku->save()){
									}else{
										mail('Zhilong.Liang@bluestargroup.com.au','Error Saving SKU',print_r($sku,1));
									}
									
									// make adjustments to the stock movement log
									$orderer = $order->vals['bill_first_name']." ".$order->vals['bill_last_name'];
									$movement_data = array(
										'stock' => $sku->vals['stock'],
										'qty' => $row['qty'],
										'id_order' => $tmp_order_no,
										'sku' => $row['sku'],
										'adjustment' => 'remove',
										'action' => 'order',
										'notes' => 'Dispatched Order [Date: '.$date.']',
										'orderer' => $orderer,
										'username' => $order->vals['username']
									);
									echo record_stock_movement($movement_data);
																
									$adjust_pending = Allowance::adjust_pending($order->val['username'], $order->val['nett'], '-');
									
									//update sku_location
									updateSKULocation($row['sku'], $row['qty']);
								}								
							}
						}
						
						if($send) {
						
						$sql = "
							UPDATE jobs j
								INNER JOIN weborder o ON o.job_id = j.id
							SET 
								j.con_note = '{$con_note}', 
								j.status = 'Complete'
							WHERE o.id_order = '{$tmp_order_no}'
						";
						$db->execute($sql);

						
						// Email: Personal Purchase Dispatched
						//send email to Zora
						if($order->vals['cost_centre'] == 'Personal Purchase')
						{
							$link = "/common/images/logo_print.gif";
							$sql = "
								SELECT *
								FROM vvv_client
								WHERE id_client = {$order->vals['id_client']}
									";
							$result = $db->read($sql);
							$clientDetail = $result->fetchRow();

							$emailContent = "
								<div>
									<img src='$link' width='223' height='34' />
								</div>
								<p>
									A personal purchase has been dispatched. <br />
									Order number: $tmp_order_no <br />
									Client: {$clientDetail['name']} <br />
									Freight cost (inc. GST): \${$totalFreight}. <br />
									Con Note: {$con_note} <br /><br />
									Regards,									
								</p>
							";
							send_mail("administration@vavavoom.com.au", "accounts@vavavoom.com.au", $subject, $emailContent, FALSE);

							$email = new Email;
							$email->setGroup('PPDS');
							$email->setSubject($subject);
							$email->setHtml($emailContent);
							//$email->send();

						}

						$subject = "BrandManager.biz Order Dispatch Confirmation";
						
						if(in_array($order->vals['id_client'], array(3782)))
						{
							$logos = "<a href='http://www.vavavoom.com'><img src='https://secure.brandmanager.biz/email/images/AMF.PNG' alt='Vavavoom' title='Go to main website'></a>";
						}
						else
						{
							$logos = "<a href='http://www.vavavoom.com'><img src='https://secure.brandmanager.biz/email/images/logoVvm.jpg' alt='Vavavoom' title='Go to main website'></a>";
						}		
						
						$confirmContent = "<html xmlns='http://www.w3.org/1999/xhtml'>
  <head>
    <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'/>
    <title>Autoresponder</title>
    <!-- Hotmail ignores some valid styling, so we have to add this -->
  </head>
  <body leftmargin='0' topmargin='0' marginwidth='0' marginheight='0' style='width: 100%;height: 100%;background-color: #efefef;margin: 0;padding: 0;-webkit-font-smoothing: antialiased'>
<!-- Wrapper -->
<table width='100%' height='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='100%' height='100%' valign='top'>	

		<!-- Main wrapper -->
		<table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td>
					<!-- Navigation -->
					<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor=''><tr>
							<td>	
									<table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><img src='https://secure.brandmanager.biz/email/bsp_images/header_backorder.jpg' alt=''></tr></table></td>
						</tr></table>
						</td>
						</tr>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='580' style='line-height: 1px; height: 40px;'>
								
							</td>
						</tr></table><table width='578' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor='#ffffff'><tr><td width='20'/>
							
							<td width='538'>
							</td>
							<td width='20'/>
						</tr><tr><td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr><tr><td width='40' height='15'/>
							<td width='518' height='15'/>
							<td width='20' height='15'/>
						</tr><tr><td width='40'/>
							
							<td width='518' valign='top' style='font-size: 14px; color: #575757; font-weight: normal; text-align: left; font-family: Helvetica, Arial, sans-serif; line-height: 20px;'>
								Hi {$order->vals['bill_first_name']}, <br/><br/>
								
								Your BrandManager order is on its way! <br/><br/>
								
								We've picked and packed your order <span style='text-decoration: none; color: #72CCD2; font-weight: bold;'>#{$order->vals['id_order']}</span> of the following products: <br/><br/>
							";
						//get the order detail
						$sql = "
							SELECT *
							FROM   weborder_item
							WHERE  id_order = '$tmp_order_no'
						";
						$result = $db->read($sql);
						$items = $result->resultSetToArray();
						if($items)
						{
							//$confirmContent .= "<table border='0' cellspacing='5' cellpadding='0'>";
							foreach($items as $item)
							{
								if ($item['qty']>0)
								{
									$confirmContent .= "<tr><td>{$item['qty']}</td><td>{$item['name']}</td><td></td><td>{$item['size']}</td><td>{$item['colour']}</td></tr>";
								}
							}
							//$confirmContent .= "</table>";
							$confirmContent .= "<br/>";
						}
						$confirmContent .= "These items have been dispatched for delivery to:
						<br/><br/><div style='color: #494949;font-weight: bold;font-style: oblique;'>{$order->vals['ship_company']} <br/>
							{$order->vals['ship_first_name']} {$order->vals['ship_last_name']} <br/>
					";
						
						if($order->vals['ship_street1'])
							$confirmContent .= $order->vals['ship_street1']."<br />";
						if($order->vals['ship_street2'])
							$confirmContent .= $order->vals['ship_street2']."<br />";
						if($order->vals['ship_street3'])
							$confirmContent .= $order->vals['ship_street3']."<br />";
						if($order->vals['ship_suburb'])
							$confirmContent .= $order->vals['ship_suburb']."<br />";
						$confirmContent .= "
						{$order->vals['ship_state']} {$order->vals['ship_postcode']} <br/>
						{$order->vals['ship_country']}
						</div>
						<br/><br/>For your reference, your package is in the capable hands of our courier partner {$order->vals['carrier']}, under consignment number {$order->vals['con_note']}.<br/><br/>
						If you have any further enquiries or comments, please contact customer service.
						<br/><br/>
                        ";
							if ($_SESSION['loginType'] == 'hc') {
								$confirmContent .= "
    						    Thanks from the team at Propeller Marketing<br/><br/>
							";
							} else {
								$confirmContent .= "All the best,    						    
							";
							}

							$confirmContent .="<br/><br/></td>
							<td width='20'/>
						</tr><tr><td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr></table>
						</td>
						</tr>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='580' style='line-height: 1px; height: 40px;'>
								
							</td>
						</tr></table><table width='578' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor=''><tr><td width='10' height='15'/>
							<td width='538' height='15'/>
							<td width='20' height='15'/>
						</tr><tr><td width='20'/>
							<td width='528'><table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><img src='https://secure.brandmanager.biz/email/bsp_images/footer.jpg' alt=''></tr></table>
							</td>
							<td width='20'/>
						</tr><tr><td width='10' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr></table>
						</td>
						</tr>
						</td>
			</tr></table><!-- End Main wrapper --></td>
	</tr></table><!-- End Wrapper --><!-- Done --></body>
</html>";

							$email = new Email;
							
							// commbank iShop
							if ($order->vals['id_client'] == 3556)
							{
								$to = $order->vals['bill_email'];
							}
							else
							{
								// Hack for TyrePlus or for any username that isn't an email address
								$to = strpos($vals['username'], '@') !== false ? $order->vals['bill_email'] : $order->vals['bill_email'];
							}
							$email->setTo($order->vals['bill_email']);
							$email->setFrom('BluestarPromote.Support@bluestargroup.com.au', 'Brand Manager');
							$email->setSubject($subject);
							$email->setHtml($confirmContent);
							
							if ('vic' != $order->vals['shipping_from'])
							{
								if ($order->vals['id_client'] != 6449)
								{
									$email->send();
								}
							}
					
				

							if (!$order->vals['client_freight_flag']) {
								$order->vals['client_freight_flag'] = '0';
							}
							if (!$order->vals['num_approval_contact']) {
								$order->vals['num_approval_contact'] = 'NULL';
							}
							
							
							$order->vals['bo_order'] = 0;

							if(!$order->save()){
								echo "AN ERROR OCCURRED WHILST DISPATCHING THIS ORDER.<br />
									  DO NOT ATTEMPT TO DISPATCH THIS ORDER AGAIN.<br />
									  PLEASE Contact Admin<br />";
								die();
							}
				}
			}
		}
	}
}

function exportMcafee() {
	global $db;
    
    $fileName = 'ExportMcafee.csv';
    
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header('Content-Description: File Transfer');
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename={$fileName}");
    header("Expires: 0");
    header("Pragma: public");
    
    $fh = @fopen( 'php://output', 'w' );
    
	$sql = "SELECT * FROM `ishop_mcafee` WHERE `order_id` IN (  SELECT `id_order` FROM `weborder` ) Order by `order_id` DESC ";
	$result = $db->read($sql);
	$order_items = $result->resultSetToArray();
	foreach ( $order_items as $item ) 
	{
		$data = array();
		$data[] = $item['sku'];
		$data[] = $item['license'];
		$data[] = $item['order_id'];	
		fputcsv($fh, $data);
	}
	 
	
        
   
    
    fclose($fh);
    exit;
}

function dispatchedReport($var) {
	
	global $db,$client_id;
	$shipping_from_clause = $var ? 'AND wo.`shipping_from` = "' . $var . '"' : '';
    $clause = "";
    if ($client_id && $client_id != 'all')
	{
        $clause = "AND wo.`id_client` = '$client_id'";
    }
    $clause .= " ORDER BY `id_order`";

    $sql = "
        SELECT  
				wo.dispatch_date,
				c.`name`,
               `id_order`,
               `job_id`,
                wo.`id_client`,
               `date`,
               `time`,
               `po`,
               `cost_centre`,
                wo.`status`,
				print_status,
               `payment_type` ,
               `bill_first_name`,
               `bill_last_name`,
				approval_date1,
				approval_date2,
				wo.`shipping_from`,
                `exported_to`,
                wo.`urgent`
        FROM `weborder` AS wo, vvv_client as c
        WHERE wo.`status` = 'placed'
        AND wo.`id_client` = c.`id_client`
		AND c.type = '{$_SESSION['loginType']}'
		AND wo.backorder = 0
		{$shipping_from_clause}
        {$clause}
    ";
    $result = $db->read($sql);
    
    $where = (is_numeric($client_id) ? "ahb.id_client = {$client_id}" : "ahb.id_client NOT IN (313,344)");
    
    $shipping_from_adhoc = $var ? "AND ahb.shipping_from = '{$var}'" : "";
    //c.name,
    $sql =
	 	"
        SELECT
			ahb.dispatch_date,
        	ahb.booking_id,
			(CASE ahb.id_client WHEN 0 THEN ahb.ship_company  ELSE c.name END) as name,
			ahb.sender,
			ahb.id_client,
			ahb.date,
			ahb.time,
			ahb.shipping_from,
			ahb.ship_first_name,
			ahb.ship_last_name,
			ahb.staff,
			ahb.po,
			ahb.print_status
		FROM
			adhoc_booking ahb
		LEFT JOIN
			vvv_client c ON c.id_client = ahb.id_client
		WHERE
			{$where}
			{$shipping_from_adhoc}
		AND
			ahb.status = 'pending'
		ORDER BY
			ahb.date DESC, ahb.time DESC
    	";
		
		$result_a = $db->read($sql);

		$fileName = 'DispatchReport.csv';
		
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header('Content-Description: File Transfer');
		header("Content-type: text/csv");
		header("Content-Disposition: attachment; filename={$fileName}");
		header("Expires: 0");
		header("Pragma: public");
		
		$fh = @fopen( 'php://output', 'w' );

		$costCenterTitle = ($client_id == 276) ? 'Division' : 'Cost Centre';
		$data = array();
		$data[] = 'Order No';
		$data[] = 'Job No';
		$data[] = 'Order Date';
		$data[] = 'Dispatch Date';
		$data[] = 'Approval Date';
		$data[] = 'Time';
		$data[] = 'Purchase Order No';
		$data[] = "{$costCenterTitle}";
		$data[] = 'Name';
		if (!is_numeric($client_id))
		{
			$data[] = "Client";
		}
		$data[] = 'Shipping From';
		$data[] = 'Export Shipping Label All / None';
		fputcsv($fh, $data);

		if($result->num_rows > 0 || $result_a->num_rows > 0)
		{
			while ($row = $result_a->fetchRow())
			{		
				$data = array();
				$date = date("d/m/Y", strtotime($row['date']));

				$data[] = "AD{$row['booking_id']}";
				$data[] = '-';
				$data[] = $date;
				if($row['dispatch_date']) {
					$data[] = date("d/m/Y", strtotime($row['dispatch_date']));
				}
				else
				{
					$data[] = '';
				}
				
				$data[] = '-';
				$data[] = $row['time'];
				$data[] = $row['po'];
				$data[] = '-';
				$data[] = $row['ship_first_name']." ".$row['ship_last_name'];			
				if (! is_numeric($client_id))
				{
					$data[] = $row['name'];
				}
				$data[] = $row['shipping_from'];    
				$data[] = "";
				fputcsv($fh, $data);            
			}
			
			while ($row = $result->fetchRow())
			{
				$data = array();
				$date = date("d/m/Y", strtotime($row['date']));

				$data[] = $row['id_order'];
				$data[] = $row['job_id'];
				$data[] = TimeDate::formatDate2($row['date']);
				if($row['dispatch_date']) {
					$data[] = date("d/m/Y", strtotime($row['dispatch_date']));
				}
				else
				{
					$data[] = '';
				}
				$tmp_approval = "";
				if($row['approval_date1']!='0000-00-00')
					$tmp_approval= TimeDate::formatDate2($row['approval_date1']);
				if($row['approval_date2']!='0000-00-00')
					$tmp_approval.= "    ". TimeDate::formatDate2($row['approval_date2']);
				$data[] = $tmp_approval;

				$data[] = $row['time'];
				$data[] = $row['po'];
				$data[] = $row['cost_centre'];
				$data[] = $row['bill_first_name']." ".$row['bill_last_name'];			
				if (! is_numeric($client_id))
				{
					$data[] = $row['name'];
				}
				$data[] = $row['shipping_from'];
				$data[] = 'Exported To: ' . $row['exported_to'];
				fputcsv($fh, $data);
			}
			
			
		}
		fclose($fh);
		exit();
}
/* Submit form */
if ($input->post)
{


// no use begin
if($_SERVER['REMOTE_ADDR'] == "120.151.150.174" || $_SERVER['REMOTE_ADDR'] == "150.101.192.90")
{

/* 	This is a bulk-dispatch script, built by Warren/Steve 25/06/2012
	Essentially, it just copies the functionality of the standard dispatch code, but 
	applies it to all orders in the $order_number_array below.
	Note that it doesn't send emails.
	
	To use:
	- Add all orders you wish to dispatch to the array
	- Update any of the other compulsory fields below (con note, freight, carrier) - they can't be empty.
	- Go through JCS and enter any order
	- Click Dispatch on that order.
	- If you're on a 4mation IP, it will ignore data submitted, and work its way through the array, dispatching all instead.
	- Be sure to comment this code-block out when you are done!!!!
*/
/* UNCOMMENT THIS CODE BLOCK TO USE BULK DISPATCHER */
	
	//Order Numbers go in this array:
	$order_number_array = array(54933,54936,54938,54942,54976,55005,55007,55011,55029,55038,55103,55108,55223,55229,55301,55306);
  
	//Compulsory Fields:
	$input->post['con_note'] = "Begin Journey";
	$input->post['freight'] = 0;
	$input->post['carrier'] = "TOLL FAST";

  
	//Rest of the code (you probably won't need to edit from here on):
    $input->post['comment'] = " ";
	  
	$date = date("Y-m-d");
	$subject= "Personal purchase order $order_no";

	//loops through our array, as set above:  
	foreach($order_number_array AS $k=>$order_no){
  		$order =& DB::record($db, 'weborder');
		$order->fetch($order_no);
    
			if(! empty($input->post['con_note']))
			{
				
				// VAVAVOOM carrier
				if(isset($input->post['freight']) && is_numeric($input->post['freight']) && ! empty($input->post['carrier']))	//valid freight value
				{
				
					$freightTax = $input->post['freight']*10/100;
					$totalFreight = $freightTax + $input->post['freight'];
					$order->set('status', 'processed');
					$order->set('freight', $input->post['freight']);
					$order->set('tax_freight', $freightTax);
					$order->set('total_freight', $totalFreight);
					$order->set('con_note', $input->post['con_note']);
					$order->set('carrier', $input->post['carrier']);
					$order->set('logistic_comment', $input->post['comment']);
					$order->set('dispatch_date', $date);
					//echo "got to here";
					//die();

					if(empty($order->vals['bo_order']) && empty($order->vals['job_id']))
					{
					
					
						// update stock location's quantity
						// get order items
						$sql = "
							SELECT `sku`, `qty`
							FROM   `weborder_item`
							WHERE  `id_order` = '$order_no'
						";
						
						$result = $db->read($sql);
						
						while ($row = $result->fetchRow())
						{
							if (! empty($row['sku']))
							{
								// reduce stock levels
	                            $sku =& DB::record($db, 'sku');
	                            $sku->fetch($row['sku']);
	                            $sku->vals['stock'] -= $row['qty'];
	                            $sku->vals['ordered'] -= $row['qty'];
	                            
	                            if ($sku->vals['ordered'] < 0)
	                            {
	                                $sku->vals['ordered'] = 0;
	                            }
	                            
	                            if (!$sku->vals['replacement_value'])
	                            {
	                                $sku->vals['replacement_value'] = 'NULL';
	                            }
	                            
	                            $sku->save();
	                            
	                            // make adjustments to the stock movement log
	                            $orderer = $order->vals['bill_first_name']." ".$order->vals['bill_last_name'];
	                            $movement_data = array(
									'stock' => $sku->vals['stock'],
									'qty' => $row['qty'],
									'id_order' => $order_no,
									'sku' => $row['sku'],
									'adjustment' => 'remove',
									'action' => 'order',
									'notes' => 'Dispatched Order [Date: '.$date.']',
									'orderer' => $orderer,
									'username' => $order->vals['username']
								);
	                            echo record_stock_movement($movement_data);
	                                                       	
	                            $adjust_pending = Allowance::adjust_pending($order->val['username'], $order->val['nett'], '-');
	                            
								//update sku_location
								updateSKULocation($row['sku'], $row['qty']);
							}
						}
					}
					
					$sql = "
						UPDATE jobs j
							INNER JOIN weborder o ON o.job_id = j.id
						SET j.con_note = '{$input->post['con_note']}'
						WHERE o.id_order = '{$order_no}'
					";
					$db->execute($sql);

                    // Email: Personal Purchase Dispatched
					//send email to Zora
					if($order->vals['cost_centre'] == 'Personal Purchase')
					{
						$emailContent = "
							<div>
								<img src='$link' width='223' height='34' />
							</div>
							<p>
								A personal purchase has been dispatched. <br />
								Order number: $order_no <br />
								Client: {$clientDetail['name']} <br />
								Freight cost (inc. GST): \${$totalFreight}. <br />
								Con Note: {$input->post['con_note']} <br /><br />
								Regards,								
							</p>
						";
						//send_mail("administration@vavavoom.com.au", "accounts@vavavoom.com.au", $subject, $emailContent, FALSE);

                        // $email = new Email;
                        // $email->setGroup('PPDS');
                        // $email->setSubject($subject);
                        // $email->setHtml($emailContent);
                        //$email->send();

					}

					$subject = "BrandManager.biz Order Dispatch Confirmation";
					
					if(in_array($order->vals['id_client'], array(3782)))
					{
						$logos =
							"
							<div>
								<img style=\"float:left; margin-right:5px; padding-right:5px;\" src=\"http://www.vavavoom.com/images/vavavoom-vive-la-difference.gif\" alt=\"Vavavoom Logo\" height=\"113\" />
								<div style=\"float:left; margin:43px 18px 0 0; height:70px; width:20px; border-right:1px solid #CCC;\"></div>
								<img style=\"float:left; margin-top:43px;\" src=\"http://secure.brandmanager.biz/clients/profiles/vvv/3782/logo.gif\" alt=\"Vavavoom Logo\" height=\"70\" />
							</div>
							<div style=\"clear:both;\"></div>
							";
					}
					else
					{
						$logos =
							"
							<div>
								<img style=\"float:left; margin-right:5px; padding-right:5px;\" src=\"http://www.vavavoom.com/images/vavavoom-vive-la-difference.gif\" alt=\"Vavavoom Logo\" height=\"113\" />
							</div>
							<div style=\"clear:both;\"></div>
							";
					}		
					
					$confirmContent = "
						{$logos}
						<p>Hi {$order->vals['bill_first_name']},</p>
						<p>Your BrandManager order is on its way!</p>
						<p>We've picked and packed your order #{$order->vals['id_order']} of the following products:</p>
						<p>
							<ul>
							";
					//get the order detail
					$sql = "
						SELECT *
						FROM   weborder_item
						WHERE  id_order = '$order_no'
					";
					$result = $db->read($sql);
					$items = $result->resultSetToArray();
					if($items)
					{
					    $confirmContent .= "<table border='0' cellspacing='5' cellpadding='0'>";
						foreach($items as $item)
						{
							if ($item['qty']>0)
							{
								$confirmContent .= "<tr><td>{$item['qty']}</td><td>{$item['name']}</td><td></td><td>{$item['size']}</td><td>{$item['colour']}</td></tr>";
							}
						}
                        $confirmContent .= "</table>";
					}
					$confirmContent .= "
						</p>
						<p>These items have been dispatched for delivery to:</p>
						<p>
							{$order->vals['ship_company']} <br />
							{$order->vals['ship_first_name']} {$order->vals['ship_last_name']} <br />
					";
					
					if($order->vals['ship_street1'])
						$confirmContent .= $order->vals['ship_street1']."<br />";
					if($order->vals['ship_street2'])
						$confirmContent .= $order->vals['ship_street2']."<br />";
					if($order->vals['ship_street3'])
						$confirmContent .= $order->vals['ship_street3']."<br />";
                    if($order->vals['ship_suburb'])
						$confirmContent .= $order->vals['ship_suburb']."<br />";
					$confirmContent .= "
						{$order->vals['ship_state']} {$order->vals['ship_postcode']} <br />
						{$order->vals['ship_country']}
						</p>
						<p>
						For your reference, your package is in the capable hands of our courier partner {$order->vals['carrier']}, under consignment number {$order->vals['con_note']}.
						</p>
						<p>
						If you have any further enquiries or comments, please contact <a href='mailto:clientservices@brandmanager.biz'>clientservices@brandmanager.biz</a>
						</p>
                        ";
                        if ($_SESSION['loginType'] == 'hc') {
						    $confirmContent .= "
    						    <p>Thanks from the team at Propeller Marketing</p>
							";
                        } else {
                            $confirmContent .= "
                                <p>All the best,</p>
							";
                        }

                        // $email = new Email;
						
                        // commbank iShop
				        if ($order->vals['id_client'] == 3556)
				        {
							$to = $order->vals['bill_email'];
				        }
				        else
				        {
        					// Hack for TyrePlus or for any username that isn't an email address
							$to = strpos($vals['username'], '@') !== false ? $order->vals['bill_email'] : $order->vals['bill_email'];
				        }
                        // $email->setTo($order->vals['bill_email']);
                        // $email->setFrom('clientservices@brandmanager.biz', 'Brand Manager');
                        // $email->setSubject($subject);
                        // $email->setHtml($confirmContent);
                        
                        if ('vic' != $order->vals['shipping_from'])
						{
							// $email->send();
						}
				
				}
				else
				{
					echo "Error 1";
					exit();
				}
			}
			else
			{
				echo "Error 2";
				exit();

			}

            if (!$order->vals['client_freight_flag']) {
                $order->vals['client_freight_flag'] = '0';
            }
            if (!$order->vals['num_approval_contact']) {
                $order->vals['num_approval_contact'] = 'NULL';
            }
            $order->vals['bo_order'] = 0;
			$order->save();

		
  
  }
  
  die('4mation Bulk Dispatcher Script Completed.');
  
/* UNCOMMENT THIS CODE BLOCK TO USE BULK DISPATCHER */  

}//no use end


	if ($input->get['var'] && ! in_array($input->get['var'], array('nsw', 'vic', 'china')))
	{
	

		// fetch the order
		$order_no = substr($input->get['var'], 1);
		$order =& DB::record($db, 'weborder');
		$order->fetch($order_no);

		//get client name
		$sql = "
			SELECT *
			FROM vvv_client
			WHERE id_client = {$order->vals['id_client']}
				";
		$result = $db->read($sql);
		$clientDetail = $result->fetchRow();

		$link = "/common/images/logo_print.gif";

        if ($input->post['update_no_of_cartons'])
		{
			$order->set('no_of_cartons', $input->post['no_of_cartons']);
			$order->save();
		}
		elseif ($input->post['re_send_invoice'])
		{
			if (re_send_invocie($order_no))
			{
				if ($order->vals['payment_type']=='account')
				{
					$response = "<span style='color: red'>The order confirmation has been sent.</span>";
				}
				elseif ($order->vals['payment_type']=='card')
				{
					$response = "<span style='color: red'>The invoice has been sent.</span>";
				}
			}
			else
			{
				if ($order->vals['payment_type']=='account')
				{
					$response = "<span style='color: red'>The order confirmation can not be sent. Please check the billing email address.</span>";
				}
				elseif ($order->vals['payment_type']=='card')
				{
					$response = "<span style='color: red'>The invoice can not be sent. Please check the billing email address.</span>";
				}
			}
			
		}
		elseif ($input->post['dispatched'])
		{
			
			


//Warren doing this using the oldy fashiony way as the class based system was broken.				
$order_array=array();
// if($_SERVER['REMOTE_ADDR'] == "120.151.150.174" || $_SERVER['REMOTE_ADDR'] == "150.101.192.90")
// {
// echo "<h1>ORDER DETAILS</h1>";
// echo "<pre>";
// print_r($order);
// echo "</pre>";
// echo "<h1>INPUT->POST DETAILS</h1>";
// echo "<pre>";
// print_r($input->post);
// echo "</pre>";
// die('4mation testing');

// }				
			$date = date("Y-m-d");
			$subject= "Personal purchase order $order_no";


			
			if(! empty($input->post['con_note']))
			{

						
				// VAVAVOOM carrier
				if(isset($input->post['freight']) && is_numeric($input->post['freight']) && ! empty($input->post['carrier']))	//valid freight value
				{

				
					$freightTax = $input->post['freight']*10/100;
					$totalFreight = $freightTax + $input->post['freight'];
					$order->set('status', 'processed');
					$order->set('freight', $input->post['freight']);
					$order->set('tax_freight', $freightTax);
					$order->set('total_freight', $totalFreight);
					$order->set('con_note', $input->post['con_note']);
					$order->set('carrier', $input->post['carrier']);
					$order->set('logistic_comment', $input->post['comment']);
					$order->set('dispatch_date', $date);

                    $order_array['status'] = 'processed';
                    $order_array['freight'] = $input->post['freight'];
                    $order_array['tax_freight'] = $freightTax;
                    $order_array['total_freight'] = $totalFreight;
                    $order_array['con_note'] = $input->post['con_note'];
                    $order_array['carrier'] = $input->post['carrier'];
                    $order_array['logistic_comment'] = $input->post['comment'];
                    $order_array['dispatch_date'] = $date;
					

					if(empty($order->vals['bo_order']) && empty($order->vals['job_id']))
					{						
						// update stock location's quantity
						// get order items
						$sql = "
							SELECT `sku`, `qty`
							FROM   `weborder_item`
							WHERE  `id_order` = '$order_no'
						";
						
						$result = $db->read($sql);

						//if(in_array(strtolower($_SESSION['username']), array('webadmin@brandmanager.biz'))) {
						//	var_dump($sql);
						//	exit;
						//}
						
						while ($row = $result->fetchRow())
						{
							if (! empty($row['sku']))
							{
								// reduce stock levels
	                            $sku =& DB::record($db, 'sku');
	                            $sku->fetch($row['sku']);
	                            $sku->vals['stock'] -= $row['qty'];
	                            $sku->vals['ordered'] -= $row['qty'];
	                            
	                            if ($sku->vals['ordered'] < 0)
	                            {
	                                $sku->vals['ordered'] = 0;
	                            }
	                            
	                            if (!$sku->vals['replacement_value'])
	                            {
	                                $sku->vals['replacement_value'] = 'NULL';
	                            }
	                            
	                            if($sku->save()){
								}else{
									mail('zhilong.liang@bluestargroup.com.au','Error Saving SKU',print_r($sku,1));
								}
	                            
	                            // make adjustments to the stock movement log
	                            $orderer = $order->vals['bill_first_name']." ".$order->vals['bill_last_name'];
	                            $movement_data = array(
									'stock' => $sku->vals['stock'],
									'qty' => $row['qty'],
									'id_order' => $order_no,
									'sku' => $row['sku'],
									'adjustment' => 'remove',
									'action' => 'order',
									'notes' => 'Dispatched Order [Date: '.$date.']',
									'orderer' => $orderer,
									'username' => $order->vals['username']
								);
	                            echo record_stock_movement($movement_data);
	                                                       	
	                            $adjust_pending = Allowance::adjust_pending($order->val['username'], $order->val['nett'], '-');
	                            
								//update sku_location
								updateSKULocation($row['sku'], $row['qty']);
							}
						}
					}
					
					$sql = "
						UPDATE jobs j
							INNER JOIN weborder o ON o.job_id = j.id
						SET 
							j.con_note = '{$input->post['con_note']}', 
							j.status = 'Complete'
						WHERE o.id_order = '{$order_no}'
					";
					
					$db->execute($sql);



// if($_SERVER['REMOTE_ADDR'] == "120.151.150.174" || $_SERVER['REMOTE_ADDR'] == "150.101.192.90")
// {
// echo "<pre>";
// echo $sql;
// print_r($input->post);
// echo "</pre>";
// die('4mation testing');
// }

					
                    // Email: Personal Purchase Dispatched
					//send email to Zora
					if($order->vals['cost_centre'] == 'Personal Purchase')
					{
						$emailContent = "
							<div>
								<img src='$link' width='223' height='34' />
							</div>
							<p>
								A personal purchase has been dispatched. <br />
								Order number: $order_no <br />
								Client: {$clientDetail['name']} <br />
								Freight cost (inc. GST): \${$totalFreight}. <br />
								Con Note: {$input->post['con_note']} <br /><br />
								Regards,
								
							</p>
						";
						send_mail("administration@vavavoom.com.au", "accounts@vavavoom.com.au", $subject, $emailContent, FALSE);

                        $email = new Email;
                        $email->setGroup('PPDS');
                        $email->setSubject($subject);
                        $email->setHtml($emailContent);
                        //$email->send();

					}

					$subject = "BrandManager.biz Order Dispatch Confirmation";
					
					if(in_array($order->vals['id_client'], array(3782)))
					{
						$logos = "<a href='http://www.vavavoom.com'><img src='https://secure.brandmanager.biz/email/images/AMF.PNG' alt='Vavavoom' title='Go to main website'></a>";
					}
					else
					{
						$logos = "<a href='http://www.vavavoom.com'><img src='https://secure.brandmanager.biz/email/images/logoVvm.jpg' alt='Vavavoom' title='Go to main website'></a>";
					}		
					
					$confirmContent = "<html xmlns='http://www.w3.org/1999/xhtml'>
  <head>
    <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'/>
    <title>Autoresponder</title>
    <!-- Hotmail ignores some valid styling, so we have to add this -->
  </head>
  <body leftmargin='0' topmargin='0' marginwidth='0' marginheight='0' style='width: 100%;height: 100%;background-color: #efefef;margin: 0;padding: 0;-webkit-font-smoothing: antialiased'>
<!-- Wrapper -->
<table width='100%' height='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='100%' height='100%' valign='top'>	

		<!-- Main wrapper -->
		<table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor=''><tr>
							<td>	
									<table width='100%' border='0' cellpadding='0' cellspacing='0' align='center'><tr><img src='https://secure.brandmanager.biz/bsp_email/images/track.jpg' alt=''/></tr></table></td>
						</tr></table>
						</td>
						</tr>
						<tr>
						<td>
						<table width='580' border='0' cellpadding='0' cellspacing='0' align='center'><tr><td width='580' style='line-height: 1px; height: 40px;'>
								
							</td>
						</tr></table><table width='578' border='0' cellpadding='0' cellspacing='0' align='center' bgcolor='#ffffff'><tr><td width='20'/>
							
							<td width='538'>
							</td>
							<td width='20'/>
						</tr><tr><td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr><tr><td width='40' height='15'/>
							<td width='518' height='15'/>
							<td width='20' height='15'/>
						</tr><tr><td width='40'/>
							
							<td width='518' valign='top' style='font-size: 14px; color: #575757; font-weight: normal; text-align: left; font-family: Helvetica, Arial, sans-serif; line-height: 20px;'>
								Hi {$order->vals['bill_first_name']}, <br/><br/>
								
								Your BrandManager order is on its way! <br/><br/>
								
								We've picked and packed your order <span style='text-decoration: none; color: #72CCD2; font-weight: bold;'>#{$order->vals['id_order']}</span> of the following products: <br/><br/>
							";
					//get the order detail
					$sql = "
						SELECT *
						FROM   weborder_item
						WHERE  id_order = '$order_no'
					";
					$result = $db->read($sql);
					$items = $result->resultSetToArray();
					if($items)
					{
					    //$confirmContent .= "<table border='0' cellspacing='5' cellpadding='0'>";
						foreach($items as $item)
						{
							//$confirmContent .= "<tr><td>{$item['qty']}</td><td>{$item['name']}</td><td></td><td>{$item['size']}</td><td>{$item['colour']}</td></tr>";
							if ($item['qty']>0)
							{
								$confirmContent .= "<div style='text-decoration: none; color: #72CCD2; font-weight: bold;'>{$item['qty']}   {$item['name']}   {$item['size']}   {$item['colour']}</div>";
							}
						}
                        //$confirmContent .= "</table>";
						$confirmContent .= "<br/>";
					}
					$confirmContent .= "These items have been dispatched for delivery to:
						<br/><br/><div style='color: #494949;font-weight: bold;font-style: oblique;'>{$order->vals['ship_company']} <br/>
							{$order->vals['ship_first_name']} {$order->vals['ship_last_name']} <br/>
					";
					
					if($order->vals['ship_street1'])
						$confirmContent .= $order->vals['ship_street1']."<br/>";
					if($order->vals['ship_street2'])
						$confirmContent .= $order->vals['ship_street2']."<br/>";
					if($order->vals['ship_street3'])
						$confirmContent .= $order->vals['ship_street3']."<br/>";
                    if($order->vals['ship_suburb'])
						$confirmContent .= $order->vals['ship_suburb']."<br/>";
					$confirmContent .= "
						{$order->vals['ship_state']} {$order->vals['ship_postcode']} <br/>
						{$order->vals['ship_country']}
						</div>
						<br/><br/>For your reference, your package is in the capable hands of our courier partner {$order->vals['carrier']}, under consignment number {$order->vals['con_note']}.<br/><br/>
						If you have any further enquiries or comments, please contact customer service.
						<br/><br/>
                        ";
                        if ($_SESSION['loginType'] == 'hc') {
						    $confirmContent .= "
    						    Thanks from the team at Propeller Marketing<br/><br/>
							";
                        } else {
                            $confirmContent .= "All the best,
							";
                        }
						$confirmContent .="<br/><br/></td>
							<td width='20'/>
						</tr><tr><td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='538' height='15' style='font-size: 0px; line-height: 1px;'/>
							<td width='20' height='15' style='font-size: 0px; line-height: 1px;'/>
						</tr></table>
						</td>
						</tr>
						</td>
			</tr></table><!-- End Main wrapper --></td>
	</tr></table><!-- End Wrapper --><!-- Done --></body>
</html>";

                        $email = new Email;
						
                        // commbank iShop
				        if ($order->vals['id_client'] == 3556)
				        {
							$to = $order->vals['bill_email'];
				        }
				        else
				        {
        					// Hack for TyrePlus or for any username that isn't an email address
							$to = strpos($vals['username'], '@') !== false ? $order->vals['bill_email'] : $order->vals['bill_email'];
				        }
                        $email->setTo($order->vals['bill_email']);
                        $email->setFrom('clientservices@vavavoom.com', 'Brand Manager');
                        $email->setSubject($subject);
                        $email->setHtml($confirmContent);
                        
                        if ('vic' != $order->vals['shipping_from'])
						{
							if ($order->vals['id_client'] != 6449)
							{
								$email->send();
							}
						}
				}
				else if(isset($input->post['client_freight_name']))
				{
					$order->set('client_freight_name', $input->post['client_freight_name']);
					$order->set('client_freight_account', $input->post['client_freight_account']);
					$order->set('status', 'processed');
					$order->set('logistic_comment', $input->post['comment']);
					$order->set('dispatch_date', $date);

                    //added ew 27/11/09 - try to tackle the stock problem.
                    if(empty($order->vals['bo_order']))
                    {
                        // update stock location's quantity
                        // get order items
                        
                    	$sql = "
                            SELECT `sku`, `qty`
                            FROM   `weborder_item`
                            WHERE  `id_order` = '$order_no'
                        ";
                        $result = $db->read($sql);
                        
                        while ($row = $result->fetchRow())
                        {
                            // reduce stock levels
                            $sku =& DB::record($db, 'sku');
                            $sku->fetch($row['sku']);
                            $sku->vals['stock'] -= $row['qty'];
                            $sku->vals['ordered'] -= $row['qty'];
                            
                            if ($sku->vals['ordered'] < 0)
                            {
                                $sku->vals['ordered'] = 0;
                            }
                            
                            if (!$sku->vals['replacement_value'])
                            {
                                $sku->vals['replacement_value'] = 'NULL';
                            }
                            
                            $sku->save();
							
                            // make adjustments to the stock movement log
                            $orderer = $order->vals['bill_first_name']." ".$order->vals['bill_last_name'];
                            $movement_data = array(
								'stock' => $sku->vals['stock'],
								'qty' => $row['qty'],
								'id_order' => $order_no,
								'sku' => $row['sku'],
								'adjustment' => 'remove',
								'action' => 'order',
								'notes' => 'Dispatched Order [Date: '.$date.', client_freight]',
								'orderer' => $orderer,
								'username' => $order->vals['username']
							);
                            echo record_stock_movement($movement_data);
                            
                            $adjust_pending = Allowance::adjust_pending($order->val['username'], $order->val['nett'], '-');
                            
                            //update sku_location
                            updateSKULocation($row['sku'], $row['qty']);
                        }
                    }

					//send email to Zora
					if($order->vals['cost_centre']=='Personal Purchase')
					{
						$emailContent = "
							<div>
								<img src='$link' width='223' height='34' />
							</div>
							<p>
								A personal purchase has been dispatched. <br />
								Order number: $order_no <br />
								Client: {$clientDetail['name']} <br />
								Freight cost (inc. GST): \${$totalFreight}. <br />
								Con Note: {$input->post['con_note']} <br /><br />
								Regards,<br />								
							</p>
						";
						send_mail("administration@vavavoom.com.au", "accounts@vavavoom.com.au", $subject, $emailContent, FALSE);

                        $email = new Email;
                        $email->setGroup('CC');
                        $email->setSubject($subject);
                        $email->setHtml($emailContent);
                        //$email->send();
					}
				}
				else
				{
					header("Location: /webadmin/logistics/new/o$order_no");
					exit();
				}
			}
			else
			{
				header("Location: /webadmin/logistics/new/o$order_no");
				exit();
			}

            if (!$order->vals['client_freight_flag']) {
                $order->vals['client_freight_flag'] = '0';
            }
            if (!$order->vals['num_approval_contact']) {
                $order->vals['num_approval_contact'] = 'NULL';
            }
			
			
            $order->vals['bo_order'] = 0;

			// $order->save();
			if(!$order->save()){
				echo "AN ERROR OCCURRED WHILST DISPATCHING THIS ORDER.<br />
					  DO NOT ATTEMPT TO DISPATCH THIS ORDER AGAIN.<br />
					  PLEASE CALL 4MATION IMMEDIATELY ON (02) 9213 1323<br />";
				die();
			}

			
// if($_SERVER['REMOTE_ADDR'] == "120.151.150.174" || $_SERVER['REMOTE_ADDR'] == "150.101.192.90")
// {
// echo "<pre>";
// print_r($order);
// echo "</pre>";
// die('4mation testing');
// }

			$state = "";
			$sql = "SELECT staff_location_id
			FROM staff
			WHERE  email='".$_SESSION['username']."'";
			$result_state = $db->read($sql);
			$row_state = $result_state->fetchRow();

			if($row_state['staff_location_id']==1) {
				$state = "/nsw";
			}
			else if($row_state['staff_location_id']==2) {
				$state = "/vic";
			}
			else if($row_state['staff_location_id']==3) {
				$state = "/china";
			}
			else
			{
				$state = "";
			}			
			header("Location: /webadmin/logistics/new".$state);
			exit();
		}
		else if ($input->post['delete_order'])
		{

			//update `optus_tees_emp` if delete the order and the employee can order again.
			//$order =& DB::record($db, 'weborder');
			//$order->fetch($tmp_order_no);
			if ($order->vals['id_client'] == 6449)
			{
				$sql = " UPDATE optus_tees_emp SET redeemed=0 WHERE employee_no ='".$order->vals['po']."' ";
				$db->execute($sql);
			}		
		
			//cancel job in jobs table,if exists
			$sql = "
			UPDATE 
				jobs j
			INNER JOIN 
				weborder o ON o.job_id = j.id
			SET 
				j.status = 'Cancelled'
			WHERE 
				o.id_order = '{$order_no}'
			";
			$db->execute($sql);
						

			/* restore stock level */
			$sql = "
			SELECT `sku`, `qty`
			FROM   `weborder_item`
			WHERE  `id_order` = '$order_no'
			";
			$result = $db->read($sql);


			
			
			while ($row = $result->fetchRow())
			{
				$sku =& DB::record($db, 'sku');
				$sku->fetch($row['sku']);

				if($sku->vals['sku'] != ""){
					$sku->vals['ordered'] -= $row['qty'];
					
					if ($sku->vals['ordered'] < 0)
					{
						$sku->vals['ordered'] = 0;
					}
					
					$sku->save();
				}
			}
			

			$sql_booking = "
				SELECT *
				FROM   `sku_booking`
				WHERE  `id_order` = '$order_no'
			";


			$res = $db->read($sql);

			if($res->getNumRows() > 0)
			{
				$booking_delete = "
				DELETE FROM  `sku_booking`
				WHERE  `id_order` = '$order_no';
				";

				$db->execute($booking_delete);
			}


            // Email: Personal Purchase Deleted
			//send email to Zora
			if($order->vals['cost_centre']=='Personal Purchase')
			{
				$subject= "Personal purchase order $order_no";
				$emailContent = "
					<div>
						<img src='$link' width='223' height='34' />
					</div>
					<p>Hi,</p>
					<p>
						A personal purchase has been deleted. <br />
						Order number: $order_no <br />
						Regards,						
					</p>
						";
				send_mail("administration@vavavoom.com.au", "accounts@vavavoom.com.au", $subject, $emailContent);

                $email = new Email;
                $email->setGroup('PPDL');
                $email->setSubject($subject);
                $email->setHtml($emailContent);
                //$email->send();

			}
			$order->set('status', 'deleted');
			$order->set('card_details', '');
			$order->save();


			$state = "";
			$sql = "SELECT staff_location_id
			FROM staff
			WHERE  email='".$_SESSION['username']."'";
			$result_state = $db->read($sql);
			$row_state = $result_state->fetchRow();

			if($row_state['staff_location_id']==1) {
				$state = "/nsw";
			}
			else if($row_state['staff_location_id']==2) {
				$state = "/vic";
			}
			else if($row_state['staff_location_id']==3) {
				$state = "/china";
			}
			else
			{
				$state = "";
			}

			header("Location: /webadmin/logistics/new".$state);			
			exit();
		}
		else if($input->post['edit_order'])
		{
			header("Location: /webadmin/logistics/edit_order/o$order_no");
			exit();
		}
		else if($input->post['download_namebadge']) {
			// download namebadge			
			
			$fileName = 'NameBadge_'.$order_no.'.csv';
    
			header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
			header('Content-Description: File Transfer');
			header("Content-type: text/csv");
			header("Content-Disposition: attachment; filename={$fileName}");
			header("Expires: 0");
			header("Pragma: public");
			
			$fh = @fopen( 'php://output', 'w' );
			
			
			$sql = "SELECT * FROM weborder_item
					WHERE id_order = {$order_no} AND sku='".$_POST['sku_namebadge']."' ";
			$result = $db->read($sql);
			//$weight = $result->fetchRow();//resultSetToArray()
			$order_items = $result->resultSetToArray();
				
			$data_title = array();
			if($client_id==3856) {
				$data_title[]='BM Order No';
				$data_title[]='Name';
				$data_title[]='Title';
				fputcsv($fh, $data_title);
				
				/*
				foreach ( $order_items as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1768 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();


					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1769 ";
					$result_tmp = $db->read($sql);
					$row_title = $result_tmp->fetchRow();



					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					$data[] = $row_title['value'];
					fputcsv($fh, $data);
				}
				*/

				$sql = "SELECT * FROM weborder_item WHERE id_order = $order_no AND print_template >0 ";
				
				$result_tpl = $db->read($sql);
				$orders_tpl = $result_tpl->resultSetToArray();

				foreach ( $orders_tpl as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1768 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();


					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1769 ";
					$result_tmp = $db->read($sql);
					$row_title = $result_tmp->fetchRow();


					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					$data[] = $row_title['value'];
					fputcsv($fh, $data);					
				}
			}
			elseif ($client_id==4065)
			{
				$data_title[]='BM Order No';
				$data_title[]='Name';
				$data_title[]='First Country';
				$data_title[]= 'Second Country';
				$data_title[]= 'Third Country';
				fputcsv($fh, $data_title);
				/*
				foreach ( $order_items as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1770 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();


					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1771 ";
					$result_tmp = $db->read($sql);
					$row_language1 = $result_tmp->fetchRow();

					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1772 ";
					$result_tmp = $db->read($sql);
					$row_language2 = $result_tmp->fetchRow();

					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1773 ";
					$result_tmp = $db->read($sql);
					$row_language3 = $result_tmp->fetchRow();



					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					$data[] = $row_language1['value'];
					$data[] = $row_language2['value'];
					$data[] = $row_language3['value'];
					fputcsv($fh, $data);
				}*/

				$sql = "SELECT * FROM weborder_item WHERE id_order = $order_no AND print_template >0 ";
				
				$result_tpl = $db->read($sql);
				$orders_tpl = $result_tpl->resultSetToArray();

				foreach ( $orders_tpl as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1770 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();

					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1771 ";
					$result_tmp = $db->read($sql);
					$row_language1 = $result_tmp->fetchRow();

					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1772 ";
					$result_tmp = $db->read($sql);
					$row_language2 = $result_tmp->fetchRow();

					$sql = "SELECT * FROM `pm_value_selectable` WHERE id_tpl = {$item['print_template']} AND id_field=1773 ";
					$result_tmp = $db->read($sql);
					$row_language3 = $result_tmp->fetchRow();

					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					$data[] = $row_language1['value'];
					$data[] = $row_language2['value'];
					$data[] = $row_language3['value'];
					fputcsv($fh, $data);				
				}
			}
			elseif ($client_id==6488)
			{
				$data_title[]='BM Order No';
				$data_title[]='Full Name';
				fputcsv($fh, $data_title);
				
				/*foreach ( $order_items as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1774 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();

					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					fputcsv($fh, $data);
				}*/

				$sql = "SELECT * FROM weborder_item WHERE id_order = $order_no AND print_template >0 ";
				
				$result_tpl = $db->read($sql);
				$orders_tpl = $result_tpl->resultSetToArray();

				foreach ( $orders_tpl as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1774 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();

					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					fputcsv($fh, $data);					
				}

			}
			elseif ($client_id==6382)
			{
				$data_title[]='BM Order No';
				$data_title[]='Full Name';
				fputcsv($fh, $data_title);
				
				/*
				foreach ( $order_items as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1775 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();

					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					fputcsv($fh, $data);
				}
				*/

				$sql = "SELECT * FROM weborder_item WHERE id_order = $order_no AND print_template >0 ";
				
				$result_tpl = $db->read($sql);
				$orders_tpl = $result_tpl->resultSetToArray();

				foreach ( $orders_tpl as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1775 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();

					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					fputcsv($fh, $data);					
				}
			}
			else if($client_id==6593) {  //LJ Hooker Name Badge
				$data_title[]='BM Order No';
				$data_title[]='Name';
				$data_title[]='Branch';
				fputcsv($fh, $data_title);

				$sql = "SELECT * FROM weborder_item WHERE id_order = $order_no AND print_template >0 ";
				
				$result_tpl = $db->read($sql);
				$orders_tpl = $result_tpl->resultSetToArray();

				foreach ( $orders_tpl as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1776 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();


					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1777 ";
					$result_tmp = $db->read($sql);
					$row_title = $result_tmp->fetchRow();


					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					$data[] = $row_title['value'];
					fputcsv($fh, $data);					
				}

				
				/*foreach ( $order_items as $item ) 
				{
					if ($item['print_template']>0)
					{
						$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1776 ";
						$result_tmp = $db->read($sql);
						$row_name = $result_tmp->fetchRow();


						$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1777 ";
						$result_tmp = $db->read($sql);
						$row_title = $result_tmp->fetchRow();


						$data = array();
						$data[] = $order_no;
						$data[] = $row_name['value'];
						$data[] = $row_title['value'];
						fputcsv($fh, $data);
					}
				}*/
			}
			else if($client_id==6597) {  //LJ Hooker NZ Name Badge
				$data_title[]='BM Order No';
				$data_title[]='Name';
				fputcsv($fh, $data_title);

				$sql = "SELECT * FROM weborder_item WHERE id_order = $order_no AND print_template >0 ";
				
				$result_tpl = $db->read($sql);
				$orders_tpl = $result_tpl->resultSetToArray();

				foreach ( $orders_tpl as $item ) 
				{
					$sql = "SELECT * FROM `pm_value_short` WHERE id_tpl = {$item['print_template']} AND id_field=1778 ";
					$result_tmp = $db->read($sql);
					$row_name = $result_tmp->fetchRow();

					$data = array();
					$data[] = $order_no;
					$data[] = $row_name['value'];
					fputcsv($fh, $data);					
				}
			}
			
			fclose($fh);
			exit;
		}
		
	}
	else
	{
		if ($input->post['dispatchAll_Spe_Submit'])
		{
			dispatchAll_Spe($input->post['exportShipping'],$input->post['con_note_value'],$input->post['carrier_name_value'],$input->post['freight_value'],$input->post['selected_sku_value']);
			$state = "";
			$sql = "SELECT staff_location_id
			FROM staff
			WHERE  email='".$_SESSION['username']."'";
			$result_state = $db->read($sql);
			$row_state = $result_state->fetchRow();

			if($row_state['staff_location_id']==1) {
				$state = "/nsw";
			}
			else if($row_state['staff_location_id']==2) {
				$state = "/vic";
			}
			else if($row_state['staff_location_id']==3) {
				$state = "/china";
			}
			else
			{
				$state = "";
			}

			header("Location: /webadmin/logistics/new".$state);
			exit();
		}

		if ($input->post['exportMcafee'])
		{
			exportMcafee();
			$state = "";
			$sql = "SELECT staff_location_id
			FROM staff
			WHERE  email='".$_SESSION['username']."'";
			$result_state = $db->read($sql);
			$row_state = $result_state->fetchRow();

			if($row_state['staff_location_id']==1) {
				$state = "/nsw";
			}
			else if($row_state['staff_location_id']==2) {
				$state = "/vic";
			}
			else if($row_state['staff_location_id']==3) {
				$state = "/china";
			}
			else
			{
				$state = "";
			}

			header("Location: /webadmin/logistics/new".$state);
			exit();
		}

		if ($input->post['Report'])
		{
			dispatchedReport($input->get['var']);
			$state = "";
			$sql = "SELECT staff_location_id
			FROM staff
			WHERE  email='".$_SESSION['username']."'";
			$result_state = $db->read($sql);
			$row_state = $result_state->fetchRow();

			if($row_state['staff_location_id']==1) {
				$state = "/nsw";
			}
			else if($row_state['staff_location_id']==2) {
				$state = "/vic";
			}
			else if($row_state['staff_location_id']==3) {
				$state = "/china";
			}
			else
			{
				$state = "";
			}

			header("Location: /webadmin/logistics/new".$state);
			exit();
		}


		if($input->post['markAsPrinted'])
		{
			if($input->post['printOrder'])
			{
				$orderToBePrinted_array = array();
				$weborder_string="";
				$adhoc_string="";
				
				foreach ($input->post['printOrder'] as $p)
				{
					if(substr($p,0,2)=="AD"){
						$adhoc_string .= "'".substr($p,2)."',";
					}else{
						$weborder_string .= "'$p',";
					}
					$orderToBePrinted_array[]=$p;
				}

				if($weborder_string != ""){
					$weborder_string=substr($weborder_string,0,-1);
					$sql = "UPDATE weborder SET print_status = 'yes' WHERE id_order IN (";
					$sql .= $weborder_string;
					$sql .= ")";
					$db->execute($sql);
				}

				if($adhoc_string != ""){
					$adhoc_string=substr($adhoc_string,0,-1);
					$adhoc_sql = "UPDATE adhoc_booking SET print_status = 'yes' WHERE booking_id IN (";
					$adhoc_sql .= $adhoc_string;
					$adhoc_sql .= ")";
					$db->execute($adhoc_sql);
				}

				// $_SESSION['orderToBePrinted'] = $orderToBePrinted;
				$_SESSION['orderToBePrinted_array'] = $orderToBePrinted_array;
				$_SESSION['printSelected']=true;

				header("Location:/webadmin/logistics/all_orders");
				exit();

			}
		}
		else if ($input->post['printPacking'])
        {
			if($input->post['printpackbox']) 
			{
				$printpack = array();

				foreach ($input->post['printpackbox'] as $v)
				{
					$printpack[] = $v;
				}				

				$_SESSION['printpackingsliplist'] = $printpack;
				header("Location:/webadmin/logistics/packing_slip_list");
				exit();				
			}
        }
		else if($input->post['printAll'])
		{
			$_SESSION['orderToBePrinted'] = false;
			$_SESSION['orderToBePrinted_array'] = false;
			$_SESSION['printSelected']=false;
			
			header("Location:/webadmin/logistics/all_orders");
			exit();
		}
		else if($input->post['dispatchAll']) //add dispatch
		{
			dispatchAll($input->post['printOrder']);
			$state = "";
			$sql = "SELECT staff_location_id
			FROM staff
			WHERE  email='".$_SESSION['username']."'";
			$result_state = $db->read($sql);
			$row_state = $result_state->fetchRow();

			if($row_state['staff_location_id']==1) {
				$state = "/nsw";
			}
			else if($row_state['staff_location_id']==2) {
				$state = "/vic";
			}
			else if($row_state['staff_location_id']==3) {
				$state = "/china";
			}
			else
			{
				$state = "";
			}

			header("Location: /webadmin/logistics/new".$state);
			exit();
		}		
		else if ($input->post['updateShippingFrom'])
		{
			foreach($input->post['shipping_from'] as $id => $val)
			{
				if(substr($id,0,2) == "AD"){
					$id = substr($id,2);
					$sql = "UPDATE adhoc_booking SET shipping_from = '{$val}' WHERE booking_id = {$id}";
				}else{
					$sql = "UPDATE weborder SET shipping_from = '{$val}' WHERE id_order = {$id}";
				}
				$db->execute($sql);
			}
		}
        else if ($input->post['exportToStarTrack'])
        {
            $ids = '';
			//Jarred 4mation 31/8/12 Need to ensure something is actually checked before exporting
			if($input->post['exportShipping']) {
				foreach ($input->post['exportShipping'] as $v)
				{
					$ids .= $v . ', ';
				}
				
				$ids = substr($ids, 0, -2);
				
				$sql = "SELECT * FROM weborder WHERE id_order IN ({$ids})";
				
				$result = $db->read($sql);
				$orders = $result->resultSetToArray();
				
				genStarTrackExport($orders);
			} else {
				$response = "<span style='color: red'>Error: Please select an order to export.</span>";
			}
        }
        else if ($input->post['exportToTOLL'])
        {
            $ids = '';
			//Jarred 4mation 31/8/12 Need to ensure something is actually checked before exporting
			if($input->post['exportShipping']) {
				foreach ($input->post['exportShipping'] as $v)
				{
					$ids .= $v . ', ';
				}
				
				$ids = substr($ids, 0, -2);
				
				$sql = "SELECT * FROM weborder WHERE id_order IN ({$ids})";
				
				$result = $db->read($sql);
				$orders = $result->resultSetToArray();
				
				genTOLLExport($orders);
			} else {
				$response = "<span style='color: red'>Error: Please select an order to export.</span>";
			}
        }
//WARREN PLATYBANKEXPORT
        else if ($input->post['exportToPlatyBank'])
        {
            $ids = '';
            $ignorelist = array();
			//Jarred 4mation 31/8/12 Need to ensure something is actually checked before exporting
			if($input->post['exportShipping']) {	
				foreach ($input->post['exportShipping'] as $v)
				{
					//look up what items are on that order.
					$sql = "SELECT * FROM weborder_item WHERE id_order = {$v} ";
					$result = $db->read($sql);
					$items = $result->resultSetToArray();
					//only include orders which have X1718 (and nothing else)
					foreach ($items as $item)
					{
						if($item['sku'] != 'X1718-999999')
						{
							$ignorelist[] = $v;
						}
					}
					if(!in_array($v,$ignorelist))
					{
						$ids .= $v . ', ';
					}
				}
				if($ids != "") {
					$ids = substr($ids, 0, -2);

					$sql = "SELECT * FROM weborder WHERE id_order IN ({$ids})";
					$result = $db->read($sql);
					$orders = $result->resultSetToArray();
					
					genPlatyBankExport($orders);
				} else {
					$response = "<span style='color: red'>Error: There were no PlatyBank orders in your selection.</span>";
				}
			} else {
				$response = "<span style='color: red'>Error: Please select an order to export.</span>";
			}
        }
		else if ($input->post['exportToLiveOrder'])
        {
            $ids = '';
			//Jarred 4mation 31/8/12 Need to ensure something is actually checked before exporting
			if($input->post['exportShipping']) {
				foreach ($input->post['exportShipping'] as $v)
				{
					$ids .= $v . ', ';
				}
				
				$ids = substr($ids, 0, -2);
				
				//$sql = "SELECT * FROM weborder WHERE id_order IN ({$ids})";

				$sql = "SELECT wi.qty, wi.sku,wi.name,wi.size,wi.colour,wi.unit_size,w.*,c.name as client_name FROM weborder_item wi
				JOIN weborder w ON w.id_order=wi.id_order
				JOIN vvv_client c ON c.id_client = w.id_client
                WHERE wi.id_order IN ({$ids})";
				
				$result = $db->read($sql);
				$orders = $result->resultSetToArray();
				
				ExportLiveOrder($orders);
			} else {
				
				if ($client_id && $client_id != 'all')
				{
					$clause = "AND w.`id_client` = '$client_id'";
				}
				$clause .= " ORDER BY w.`id_order`";

				$sql = "SELECT wi.qty, wi.sku, wi.name,wi.size,wi.colour,wi.unit_size,w.*,c.name as client_name FROM weborder_item wi
				JOIN weborder w ON w.id_order=wi.id_order
				JOIN vvv_client c ON c.id_client = w.id_client
                WHERE w.status = 'placed'	AND w.backorder = 0 {$clause} ";
				$result = $db->read($sql);
				$orders = $result->resultSetToArray();				
				ExportLiveOrder($orders);
			}
        }
        else if ($input->post['exportToDailyOrder'])
        {
            $ids = '';
			$today = `CURDATE()`;
				
			if ($client_id && $client_id != 'all')
			{
				$clause = "AND w.`id_client` = '$client_id' ";
			}
			$clause .= " ORDER BY w.`id_order`";

			$sql = "SELECT wi.qty, wi.sku, wi.name,wi.price as unit_price, wi.size,wi.colour,wi.unit_size,w.*,c.name as client_name FROM weborder_item wi
			JOIN weborder w ON w.id_order=wi.id_order
			JOIN vvv_client c ON c.id_client = w.id_client
			WHERE  w.status = 'placed' and w.date = CURDATE()	";// w.date >= CURDATE()"; //AND w.print_status = 'yes'  w.status = 'placed' AND
			$result = $db->read($sql);
			$orders = $result->resultSetToArray();				
			ExportDailyOrder($orders);

        }
	}
}


/* Display a specific order */

if ($input->get['var'] && ! in_array($input->get['var'], array('nsw', 'vic', 'china')))
{


	
    // fetch the order
    $order_no = substr($input->get['var'], 1);
    $order =& DB::record($db, 'weborder');
    $order->fetch($order_no);

	
// Warren 27/07/2012 - I am limiting this page to show NEW orders
// WHY THIS WAS NEVER DONE, I DO NOT KNOW.
// if($order->vals['status']!="placed"){
	//redirect to the new orders main page.
	// header("Location: /webadmin/logistics/new");
	// exit();
// }
// if($_SERVER['REMOTE_ADDR'] == "120.151.150.174" || $_SERVER['REMOTE_ADDR'] == "150.101.192.90"){
// }

	
	
	$isBo = checkBO($order_no);		//check if this order contains back order items

    // build the order table
    $sql = "
        SELECT *
        FROM   weborder_item wi LEFT JOIN sku s ON wi.sku = s.sku
        WHERE  wi.id_order = '$order_no'
    ";
    //echo $sql;
	$result = $db->read($sql);
    $items = $result->resultSetToArray();

	//get all the sku bookings if the item is a signage
	foreach($items as $key => $val)
	{
		if($val['sku_booking'] == 1 )
		{
			 $sql_sku = "
				SELECT *
				FROM sku_booking
				WHERE  id_order = '$order_no' AND sku = '{$val['sku']}'";

			$result_one = $db->read($sql_sku);
			$sku_booking = $result_one->fetchRow();
			$items[$key]['sku_booking'] = $sku_booking;

		}
	}

	if(!$isBo)
		$order_table = buildOrderTable($items, $order);
	else
		$order_table = buildOrderTable($items, $order, "Items marked red have a stock level are less than or equal to zero");
		//$order_table = buildOrderTable($items, $order, "Items marked red have a stock level of zero");



    // create the order form
    $form =& new Form($base_path.'protected/products/forms/order.ini');

    while (list ($key, $vals) = each ($form->field_definitions) )
	{
        if (!isset($form->field_definitions[$key]['value']) || !$form->field_definitions[$key]['value'])
		{
            $form->field_definitions[$key]['value'] = $order->vals[$key];
        }
    }
	$form->init();

    // payment
    if ($order->vals['payment_type'] == 'card')
	{
        require_once 'Crypt.php';
        $crypt =& new Crypt(CRYPT_KEY);
//Warren added >>>>>> $order->vals['card_details'] != "NULL"  <<<<< 20/08/2012 as errors were occuring in the Crypt when blank details were attempted to be encrypted.
		if ($order->vals['card_details'] && $order->vals['card_details'] != "NULL")
		{
            $card = unserialize($crypt->decrypt($order->vals['card_details']));
            $payment = "
                <table border='0' cellpadding='0' cellspacing='0'>
                <tr><td><strong>Name:</strong></td><td>{$card['card_name']}</td></tr>
                <tr><td><strong>Type:</strong></td><td>{$card['card_type']}</td></tr>
                <tr><td><strong>Number:</strong></td><td>{$card['card_no']} (first part)</td></tr>
                <tr><td><strong>Security Code:</strong></td><td>{$card['cvc2']}</td></tr>
                <tr><td><strong>Expiry:</strong></td><td>{$card['expiry_month']} / {$card['expiry_year']}</td></tr>
                </table>
            ";
        }
		else
		{
             $payment = "Card - details deleted";
        }


    }
	else
	{
        $payment = 'Account';
    }

	require_once 'order.inc.php';	//order details
	
	$main .= "
		<div style='margin: 0'>
			<table>
				<tr>
					<td>Picked by:  ............................................</td>
					<td>Checked by: ............................................</td>
					<td>Carrier: .....................................................</td>
				</tr>
					<td>Date:  .....................................................</td>
					<td>Date:  ........................................................</td>
					<td>Con. Note:  ................................................</td>
				<tr>
					<td></td>
					<td></td>
					<td>Dispatch Date: .........................................</td>
				</tr>
			</table>
		</div>
			";

	//process order form starts here
	$main .= "<form action='' method='post' id='process_order_form'>";
	if(!$isBo)
	{
		ob_start();
		?>
	        <script type="text/javascript">
				$(function() {
					var carrierTags = ["Australian Air Express", "Capital", "Client Courier", "Client Pickup", "Couriers Please", "Dropped Off", "Express Post", "Fastway", "First", "FRF Couriers", "Mail/Parcel Post", "Star Track", "TNT", "Toll Priority"];
					var serviceTags = ["1kg Bag", "3kg Bag", "5kg Bag", "9am", "Courier", "Express", "Flatbed/Tautliner", "Lift Back", "Overnight", "Premium", "Same Day", "Van or Ute 1/2 Tonne", "Van or Ute 1 Tonne", "Van or Ute 2 Tonne", "VIP"];
					$("#carrier_name").autocomplete({
						source: carrierTags,
						minLength: 0
					}).dblclick(function() {
						$(this).autocomplete("search", '');
					});
                    $("#service").autocomplete({
						source: serviceTags,
						minLength: 0
					}).dblclick(function() {
						$(this).autocomplete("search", '');
					});
				});
			</script>
        <?php
        $main .= ob_get_clean();
        $main .= "
			<div class='noprint'>
				<h2>Freight Information</h2>
				<table>
					<tr>
						<td>No. of Cartons</td>
						<td>
							<input type='text' name='no_of_cartons' value='{$order->vals['no_of_cartons']}' class='textfield-med' />
							<input type='submit' name='update_no_of_cartons' value='Update No. Of Cartons' />
						</td>

					</tr>
					<tr>
						<td>*Con. Note</td>
						<td><input type='text' name='con_note' value='' id='con_note' class='textfield' /></td>
					</tr>
			";
		/* Freight information added 1/23/2007 */
		// only available if the client chose VAVAVOOM carrier
		if(empty($order->vals['client_freight_flag']))
		{
			$main .= "
					<tr>
						<td>*Carrier name:</td>
						<td><input type='text' name='carrier' id='carrier_name' value='' class='textfield-med' /></td>
					</tr>
                    <tr>
						<td>Service:</td>
						<td><input type='text' name='service' id='service' value='' class='textfield-med' /></td>
					</tr>
					<tr>
						<td>*Freight Value (exc. GST)</td>
						<td><input type='text' name='freight' value='' id='freight' class='textfield-med' />
                    ";
                    if($order->vals['estimate_freight'] > 0) {
                        $main .= "Freight Estimate: $".$order->vals['estimate_freight'];
                    }

                    $main .= "</tr>";
		}
		else
		{
			$main .= "
					<tr>
						<td>Client carrier name:</td>
						<td><input type='text' name='client_freight_name' value='{$order->vals['client_freight_name']}' /></td>
					</tr>
					<tr>
						<td>Client carrier account number:</td>
						<td><input type='text' name='client_freight_account' value='{$order->vals['client_freight_account']}' /></td>
					</tr>
					";
		}
		$main .= "
					<tr>
						<td>Logistics Comment</td>
						<td><textarea name='comment' cols='30' rows='5'></textarea></td>
					</tr>
                    ";
        if(empty($order->vals['client_freight_flag']) AND $order->vals['estimate_freight'] > 0) {
            $main .= "
                    <tr>
                        <td colspan='2'>
                            Please advise Manager if actual freight
                            costs are not within a $15 range
                            (higer or lower than estimate)
                        </td>
                    </tr>
                    ";
        }
        $main .= "
				</table>
			</div>
			<div id='function'>
				";
	}

	if ($_SESSION['read_weborder']!="1")
	{
		if(!$isBo)
		{
			if ($order->vals['payment_type']=='account')
			{
				$main .= "<input type='submit' name='re_send_invoice' id='re_send_invoice' value='Resend Order Confirmation' />";
			}
			elseif ($order->vals['payment_type']=='card')
			{
				$main .= "<input type='submit' name='re_send_invoice' id='re_send_invoice' value='Resend Invoice' />";
			}

			
			$main .= "<input type='submit' name='dispatched' id='dispatched' value='Dispatch Order' />";
			
			$main .= "<input type='button' name='shipping_label' value='Shipping Label' onclick=\"window.open('/webadmin/logistics/shipping_label/o$order_no','mywindow','toolbar=yes,location=yes,directories=yes,status=yes,menubar=yes,scrollbars=yes,copyhistory=yes,resizable=yes')\">";

		// for now this allows only a backorder button for Allianz
		// in future this needs to allow backorder button for anyone who
		// has backorders enabled
		} elseif ($_SESSION['client']['id_client'] == 50) {
			$main .= "<input type='submit' name='makeBO' id='makeBO' value='Generate Back Order' />";
		}

		$main .= "<input type='button' name='packing_slip' value='Packing Slip' onclick=\"window.open('/webadmin/logistics/packing_slip/o$order_no','mywindow','toolbar=yes,location=yes,directories=yes,status=yes,menubar=yes,scrollbars=yes,copyhistory=yes,resizable=yes')\">";

		$main .= $print_button;
		if(in_array($client_id,array(3856,4065,6488,6382,6593,6597))) {
			$show_btn_namebadge=false;
			//check items if name badge exist will show download name badge button
			foreach($items as $item)
			{
				//if($item['sku']=='NAMEBADGE01'||$item['sku']=='HSBC020') {
				if(in_array($item['sku'],array('NAMEBADGE01','HSBC020','BOQNAMEBADGE','HCKNAMEBADGE','LJH996','LJHNBNZ'))) {
					$show_btn_namebadge = true;
				}
			}

			if($show_btn_namebadge) {
				$main .= "
				<input type='hidden' name='sku_namebadge' value='".$item['sku']."'>
				<input type='submit' name='download_namebadge' id='download_namebadge' value='Download Name Badge CSV' />";
			}
		}
		$main .= "<input type='submit' name='edit_order' id='edit_order' value='Edit Order' />";
		$main .= "<input type='submit' name='delete_order' id='delete_order' value='Delete Order' />";

	}
	$main .= "</div>";
	$main .= "</form>";

}
/* display all orders */
else
{
	$main .= 
		"
		<!--Filter By Shipping From:
		<select name='shipping_from' id='shipping_from'>
			<option value=''" . (! $input->get['var'] ? 'selected="selected"' : '') . ">ALL</option>
			<option value='nsw'" . ($input->get['var'] == 'nsw' ? 'selected="selected"' : '') . ">NSW</option>
			<option value='vic'" . ($input->get['var'] =='vic' ? 'selected="selected"' : '') . ">Vic</option>
			<option value='china'" . ($input->get['var'] =='china' ? 'selected="selected"' : '') . ">China</option>
		</select>-->
		";

	$shipping_from_clause = $input->get['var'] ? 'AND wo.`shipping_from` = "' . $input->get['var'] . '"' : '';
    // build the SQL clause to get just a particular clients
    // records if current client selected
    $clause = "";
    if ($client_id && $client_id != 'all')
	{
        $clause = "AND wo.`id_client` = '$client_id'";
    }
	else if($client_id == 'all') {
		//$clause = "AND wo.`id_client` <> 4239";
	}
    $clause .= " ORDER BY `id_order`";

    $sql = "
        SELECT  c.`name`,
               `id_order`,
               `job_id`,
                wo.`id_client`,
               `date`,
               `time`,
               `po`,
               `cost_centre`,
                wo.`status`,
				print_status,
               `payment_type` ,
               `bill_first_name`,
               `bill_last_name`,
				approval_date1,
				approval_date2,
				wo.`shipping_from`,
                `exported_to`,
                wo.`urgent`
        FROM `weborder` AS wo, vvv_client as c
        WHERE wo.`status` = 'placed'
        AND wo.`id_client` = c.`id_client`
		AND c.type = '{$_SESSION['loginType']}'
		AND wo.backorder = 0
		{$shipping_from_clause}
        {$clause}
    ";
    $result = $db->read($sql);
    
    
    // $where = (is_numeric($_SESSION['client_id']) ? "ahb.id_client = {$_SESSION['client_id']}" : "ahb.id_client NOT IN (313,344)");
    $where = (is_numeric($client_id) ? "ahb.id_client = {$client_id}" : "ahb.id_client NOT IN (313,344)");
    
    $shipping_from_adhoc = $input->get['var'] ? "AND ahb.shipping_from = '{$input->get['var']}'" : "";
    //c.name,
    $sql =
	 	"
        SELECT
        	ahb.booking_id,
			(CASE ahb.id_client WHEN 0 THEN ahb.ship_company  ELSE c.name END) as name,
			ahb.sender,
			ahb.id_client,
			ahb.date,
			ahb.time,
			ahb.shipping_from,
			ahb.ship_first_name,
			ahb.ship_last_name,
			ahb.staff,
			ahb.po,
			ahb.print_status
		FROM
			adhoc_booking ahb
		LEFT JOIN
			vvv_client c ON c.id_client = ahb.id_client
		WHERE
			{$where}
			{$shipping_from_adhoc}
		AND
			ahb.status = 'pending'
		ORDER BY
			ahb.date DESC, ahb.time DESC
    	";
		
		$result_a = $db->read($sql);

if (in_array($_SERVER['REMOTE_ADDR'], array('120.151.150.174','150.101.192.90')))
{
// echo $sql;
}

	if($result->num_rows > 0 || $result_a->num_rows > 0)
	{
        $costCenterTitle = ($client_id == 276) ? 'Division' : 'Cost Centre';
		$main .= "
		<form id='allOrders' method='post' action=''>
		<table id='noURL-table' class='order-table'>
			<tr>
				<th><span style='cursor:pointer' onClick='checkCheckGroup(document.forms.allOrders, \"printOrder[]\")'>All</span> / <span style='cursor:pointer' onClick='unCheckCheckGroup(document.forms.allOrders, \"printOrder[]\")'>None</span></th>
				<th></th>
				<th>Order No</th>
				<th>Job No</th>
				<th>Order Date</th>
				<th>Approval Date</th>
				<th>Time</th>
				<th>Purchase Order No</th>
				<th>{$costCenterTitle}</th>
				<th>Name</th>
		";
				if (!is_numeric($client_id))
				{
					$main .= "<th>Client</th>";
				}
				$main .= "<th>Shipping From</th>";
                $main .= "<th>Export Shipping Label<br /><span style='cursor:pointer' onClick='checkCheckGroup(document.forms.allOrders, \"exportShipping[]\")'>All</span> / <span style='cursor:pointer' onClick='unCheckCheckGroup(document.forms.allOrders, \"exportShipping[]\")'>None</span></th>";
                $main .= "<th>Packing Slip<br /><span style='cursor:pointer' onClick='checkCheckGroup(document.forms.allOrders, \"printpackbox[]\")'>All</span> / <span style='cursor:pointer' onClick='unCheckCheckGroup(document.forms.allOrders, \"printpackbox[]\")'>None</span></th>";

		$main .= "</tr>";

		$orders = "";

	    $count = 1;

		$today = date('Y-m-d');
	    
		while ($row = $result_a->fetchRow())
		{
			$style = ($count == $result_a->num_rows ? 'border-bottom:2px solid #ddd;' : '');
			
			$orders .= "
				<tr style=\"$style\">
					<td>";
			if($row['print_status']=='no')
			{
				$orders .= "<input type='checkbox' name='printOrder[]' value='AD{$row['booking_id']}' />";
			}
			$orders .= "
					</td>
					<td></td>
					<td><a style='font-weight:bold;color:#E42518;' href=\"/webadmin/logistics/adhoc/{$row['booking_id']}\">AD{$row['booking_id']}</a></td>";
			
			$date = date("d/m/Y", strtotime($row['date']));

			$due_date = date('Y-m-d', strtotime($row['date']. ' + 2 day'));
			$due_date_class = "";
			if ($today> $due_date)
			{
				$due_date_class = " style=' background-color:#FF3840; color:white;' ";
			}                
			
			$orders .= 
				"
				<td>-</td>
				<td {$due_date_class}>{$date}</td>
				";
			
			$orders .= 
				"
				<td>-</td>
				<td>{$row['time']}</td>
				<td>{$row['po']}</td>
				<td>-</td>
				<td>{$row['ship_first_name']} {$row['ship_last_name']}</td>
				";
			
			if (! is_numeric($client_id))
			{
				$orders .= "
					<td>{$row['name']}</td>
				";
			}
			
			$orders .= 
				'<td>
					<select name="shipping_from[AD' . $row['booking_id'] . ']" id="shipping_from">
						<option></option>
						<option value="nsw"' . ($row['shipping_from'] == 'nsw' ? ' selected="selected"' : '') . '>NSW</option>
						<option value="vic"' . ($row['shipping_from'] == 'vic' ? ' selected="selected"' : '') . '>Vic</option>
						<option value="china"' . ($row['shipping_from'] == 'china' ? ' selected="selected"' : '') . '>China</option>
		            </select>
	            </td>
	        	';
			
            $orders .= 
            	"
            		<td>
					</td>
					<td>
					</td>
            	</tr>
            	";
            
            $count++;
		}
		
		while ($row = $result->fetchRow())
		{
			$orders .= "
				<tr>
					<td>
					";
			if($row['print_status']=='no')
			{
				$orders .= "
						<input type='checkbox' name='printOrder[]' value='{$row['id_order']}' />
						";
			}
			
			$urgent = ($row['urgent'] ? '<img align="center" src="/jcs/images/red_flag.gif" />' : null);
			
			$orders .= "
					</td>
					<td>{$urgent}</td>
					<td><a href='/webadmin/logistics/new/o{$row['id_order']}' style='font-weight:bold;color:#E42518;'>";
			$orders .= "{$row['id_order']}</a></td>";
			$orders .= '<td>' . (! empty($row['job_id']) ? "<a href='/webadmin/sales/edit_job/{$row['job_id']}/' style='font-weight:bold;color:#E42518;'>{$row['job_id']}</a>" : '') . '</td>';

			$due_date = date('Y-m-d', strtotime($row['date']. ' + 2 day'));
			$due_date_class = "";
			if ($today> $due_date)
			{
				$due_date_class = " style=' background-color:#FF3840; color:white;' ";
			} 

			$orders .= '<td '.$due_date_class.'>' . TimeDate::formatDate2($row['date']);
			$orders .= "
					</td>
					<td>
					";
			if($row['approval_date1']!='0000-00-00')
				$orders.= TimeDate::formatDate2($row['approval_date1']);
			if($row['approval_date2']!='0000-00-00')
				$orders.= "<br />". TimeDate::formatDate2($row['approval_date2']);
			$orders .= "
					</td>
					<td>{$row['time']}</td>
					<td>{$row['po']}</td>
					<td>{$row['cost_centre']}</td>
					<td>{$row['bill_first_name']} {$row['bill_last_name']}</td>
			";
			if (!is_numeric($client_id))
			{
				$orders .= "
					<td>{$row['name']}</td>
				";
			}
			$orders .= '<td><select name="shipping_from[' . $row['id_order'] . ']" id="shipping_from">
							<option></option>
							<option value="nsw"' . ($row['shipping_from'] == 'nsw' ? ' selected="selected"' : '') . '>NSW</option>
							<option value="vic"' . ($row['shipping_from'] == 'vic' ? ' selected="selected"' : '') . '>Vic</option>
							<option value="china"' . ($row['shipping_from'] == 'china' ? ' selected="selected"' : '') . '>China</option>
			            </select></td>';
		
			if ( ! empty($row['exported_to']) && $row['exported_to'] != "NULL" )
            {
                $orders .= '<td>Exported To: ' . $row['exported_to'] . '</td>';
            }
            else
            {
                $orders .= '<td><input type="checkbox" name="exportShipping[]" value="' . $row['id_order'] . '" /></td>';
            }
			$orders .= '<td><input type="checkbox" name="printpackbox[]" value="' . $row['id_order'] . '" /></td>';
            $orders .= '</tr>';
		}
		
		

		$main .= $orders."</table>";

		if ($_SESSION['read_weborder']!="1")
		{
				$main .= "
				<input type='submit' name='markAsPrinted' value='Print Selected Orders' />
				<input type='submit' name='printAll' value='Print All Orders' />
				<input type='submit' name='updateShippingFrom' value='Update Shipping From Values' />
				<input type='submit' name='exportToStarTrack' value='Star Track Export' />
				<input type='submit' name='exportToTOLL' value='TOLL Export' />
				<input type='submit' name='exportToLiveOrder' value='Live Order Export Report' />
				<input type='submit' name='exportToDailyOrder' value='Daily Order Export Report' />
				<input type='submit' name='printPacking' value='Print Packing Slip Selected Orders' />";
				//WARREN PLATYBANKEXPORT
		//if(in_array($_SERVER['REMOTE_ADDR'], array('120.151.150.174','150.101.192.90')))
		//{
			if ($_SESSION['client']['id_client'] == 310)
			{
				$main .= " <input type='submit' name='exportToPlatyBank' value='PlatyBank Export' />";
			}
		//}  

		}
		//jason.jackson@vavavoom.com
		if(isset($_SESSION['username'])) {		
			if(in_array(strtolower($_SESSION['username']), array('richard.karora@bluestargroup.com.au','jason.jackson@bluestargroup.com.au','webadmin@brandmanager.biz'))) {

				$main .=" <input type='submit' name='dispatchAll' value='Dispatch All' />";
				$main .=" <input type='button'  name='dispatchAll_special' id='dispatchAll_special' value='Dispatch Special' />";
				$main .=" <input type='submit' name='exportMcafee' value='Export McaFee' />";
				$main .=" <input type='submit' name='Report' value='Report' />";
		$main .=" <div style=' display:none;'>
		<a href='#test' id='select_sku' name='select_sku'>&nbsp;</a>
		</div>
<div id='test' style='display:none; width:350px; height:300px; margin: 0;'>
<table width='100%'>
		<tr>
		<td>
Please select SKU:</td>
<td>
<select name='dispatch_SKU' id='dispatch_SKU'>
			<option value='MCAFTP' selected='selected'>MCAFTP</option>			
			<option value='MCAFIS'>MCAFIS</option>	
			<option value='2013BCD'>2013BCD</option>	
			<option value='CBA-2014'>CBA-2014</option>
			<option value='WODIARY-15'>WODIARY-15</option>
		</select></td>
		</tr>
		
		<tr>
		<td>*Con. Note </td>
		<td><input type='text' name='con_note' value='DOWNLOAD' id='con_note' class='textfield'></td>
		</tr>
		<tr>
		<td>*Carrier name: </td>
		<td><input type='text' name='carrier_name' id='carrier_name' value='NONE' class='textfield-med' ></td>
		</tr>
		<tr>
		<td>*Freight Value (exc. GST):</td>
		<td><input type='text' name='freight' value='0' id='freight' class='textfield-med'></td>
		</tr>
		<tr>
		<td colspan='2'><input type='submit' name='dispatchAll_Spe' id='dispatchAll_Spe' value='Dispatch All' />
</td>
</tr>
</table></div>
<input type='text' name='selected_sku_value' value='' id='selected_sku_value' class='textfield' style='width:0px;height:0px;margin:-10000px;'>
<input type='text' name='con_note_value' value='' id='con_note_value' class='textfield' style='width:0px;height:0px;margin:-10000px;'>
<input type='text' name='carrier_name_value' id='carrier_name_value' value='' class='textfield-med' style='width:0px;height:0px;margin:-10000px;'>
<input type='text' name='freight_value' value='' id='freight_value' class='textfield-med' style='width:0px;height:0px;margin:-10000px;'>
<input type='submit' name='dispatchAll_Spe_Submit' id='dispatchAll_Spe_Submit' value='submit' style='width:0px;height:0px;margin:-10000px;' />";

			}
		}
        
        $main .="</form>";

	}
	else
		$response = "<span class='error'>There are no new orders.</span>";
}



$main .= "
			<script type='text/javascript'>
			$(function(){
				$('#shipping_from').change(function(){
					window.location.replace('/webadmin/logistics/new/' + $(this).val() + '/');
				})
			});
			</script>
			";

//debug($input->get, '$input->get');

?>