<?php
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout.php'); exit; }
if (!hash_equals($_SESSION['csrf_order'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid checkout token.'); }
const DATA_FILE = __DIR__ . '/data.json';
function load_data(): array { $d=json_decode(file_get_contents(DATA_FILE),true); return is_array($d)?$d:[]; }
function clean_text($v): string { return trim(strip_tags((string)$v)); }
function clean_phone($v): string { return preg_replace('/[^0-9]/','',(string)$v); }
$data=load_data(); $data += ['dishes'=>[],'hotels'=>[],'contacts'=>[],'users'=>[],'orders'=>[],'reviews'=>[]];
$cart=json_decode($_POST['cart_json']??'[]',true);
if(!is_array($cart)||empty($cart)){$_SESSION['flash']=['type'=>'error','msg'=>'Your cart is empty.'];header('Location: checkout.php');exit;}
$catalog=[];foreach($data['dishes'] as $dish)$catalog[(string)$dish['id']] = $dish;
$items=[];$subtotal=0;
foreach($cart as $row){$id=(string)($row['id']??'');$qty=max(1,min(20,(int)($row['qty']??1)));if(!isset($catalog[$id]))continue;$d=$catalog[$id];$line=(int)$d['price']*$qty;$subtotal+=$line;$items[]=['dish_id'=>(int)$d['id'],'name'=>$d['name'],'hotel'=>$d['hotel'],'price'=>(int)$d['price'],'qty'=>$qty,'line_total'=>$line];}
if(!$items){$_SESSION['flash']=['type'=>'error','msg'=>'No valid dishes were found in your cart.'];header('Location: checkout.php');exit;}
$promo=strtoupper(trim(clean_text($_POST['promo']??'')));$delivery=50;$discount=($promo==='LYAIDEU'||$promo==='FOODXPRESS')?50:0;$total=max(0,$subtotal+$delivery-$discount);
$next=0;foreach($data['orders'] as $o)$next=max($next,(int)($o['id']??0));
$order=['id'=>$next+1,'user_id'=>(int)$_SESSION['user']['id'],'customer_name'=>clean_text($_POST['customer_name']??$_SESSION['user']['name']),'phone'=>clean_phone($_POST['phone']??$_SESSION['user']['phone']),'address'=>clean_text($_POST['address']??''),'note'=>clean_text($_POST['note']??''),'payment'=>clean_text($_POST['payment']??'Cash on Delivery'),'promo'=>$promo,'subtotal'=>$subtotal,'delivery_fee'=>$delivery,'discount'=>$discount,'total'=>$total,'status'=>'Pending','created'=>date('Y-m-d H:i:s'),'items'=>$items];
if($order['address']===''){$_SESSION['flash']=['type'=>'error','msg'=>'Please enter your delivery address.'];header('Location: checkout.php');exit;}
$data['orders'][]=$order;$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if($json===false||file_put_contents(DATA_FILE,$json.PHP_EOL,LOCK_EX)===false){http_response_code(500);exit('Could not save order.');}
$_SESSION['last_order_id']=$order['id'];header('Location: order_success.php?id='.$order['id']);exit;