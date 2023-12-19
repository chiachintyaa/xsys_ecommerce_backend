<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BreadcrumbImage;
use Auth;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\Address;
use App\Models\Vendor;
use App\Models\Setting;
use App\Models\Wishlist;
use App\Models\MidtransPayment;
use App\Models\MidtransOrder;
use App\Models\ShoppingCart;
use App\Models\Coupon;
use App\Models\Shipping;
use App\Models\Order;
use Cart;
use Str;
use Session;
class MidtransController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');//->except('callback_finished','callback_unfinished','callback_error');
    }

    public function get_order_id(Request $request) {
        $user = Auth::guard('api')->user();
        $orderid_previous = Order::where('order_id','like','XMB1623%')->max(\DB::raw('substr(order_id, 8, 7)'));

        $id = "XMB1623";
        $number = "0000001";
        if ($orderid_previous == null) {
            return response()->json([
                'orderid_next' => $id . "" . $number
            ],200);
        } else {
            $seri = intval($orderid_previous) + 1;
            $orderid_next = $id ."". str_pad($seri, 7, '0', STR_PAD_LEFT);
            return response()->json([
                'orderid_previous' => $id . $orderid_previous,
                'orderid_next' => $orderid_next
            ],200);
        }
    }

    public function get_snaptoken(Request $request) {
        // Set your Merchant Server Key
        \Midtrans\Config::$serverKey = env('MIDTRANS_SERVERKEY');
        // Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
        \Midtrans\Config::$isProduction = true;
        // Set sanitization on (default)
        \Midtrans\Config::$isSanitized = true;
        // Set 3DS transaction for credit card to true
        \Midtrans\Config::$is3ds = true;

        $transactiondetails = $request->transaction_details;
        $customerdetails = $request->customer_details;
        // $item_details = $request->item_details;
        $address_shipping = $request->address_shipping;
        $address_billing = $request->address_billing;

        $user = Auth::guard('api')->user();
        $cartProducts = ShoppingCart::with('product','variants.variantItem')->where('user_id', $user->id)->select('id','product_id','qty')->get();
        $shipping = Address::with('country','state','city')->where(['id' => $address_shipping])->first();
        $billing = Address::with('country','state','city')->where(['id' => $address_billing])->first();


        $params = array(
            'transaction_details' => array(
                'order_id' => $transactiondetails["order_id"],
                'gross_amount' => $transactiondetails["gross_amount"],
            ),
            'customer_details' => array(
                'first_name' => $customerdetails["first_name"],
                'last_name' => $customerdetails["last_name"],
                'email' => $customerdetails["email"],
                'phone' => $customerdetails["phone"],
                'billing_address' => array(
                    'first_name' => $billing['name'],
                    'last_name' => '',
                    'email' => $billing['email'],
                    'phone' => $billing['phone'],
                    'address' => $billing['address'],
                    'city' => $billing['city']['name'],
                    'postal_code' => '',
                    'country_code' => ''
                ),
                'shipping_address' => array(
                    'first_name' => $shipping['name'],
                    'last_name' => '',
                    'email' => $shipping['email'],
                    'phone' => $shipping['phone'],
                    'address' => $shipping['address'],
                    'city' => $shipping['city']['name'],
                    'postal_code' => '',
                    'country_code' => ''
                )
            ),
        );

        $items = array();
        foreach($cartProducts as $index => $value) {
            array_push($items, array(
                "id" => $value['product']['id'],
                "price" => $value['product']['price'],
                "quantity" => $value['qty'],
                "name" => $value['product']['name']
            ));
        }

        $params['item_details'] = $items;

        $snapToken = \Midtrans\Snap::getSnapToken($params);

        return response()->json([
            'token' => $snapToken,
            // 'params' => $params
            'items' => $items,
            // 'addr_shipping' => $address_shipping,
            // 'addr_billing' => $address_billing,
            // 'rule' => $rule
        ], 200);
    }

    public function callback_finished(Request $request) {
        $order_id = $request->order_id;
        $status_code = $request->status_code;
        $transaction_status = $request->transaction_status;
        $result = $request->result;

        //asumsinya di hit otomatis dan di hit by click checkout
        $statusCode = 500;
        $statusMessage = "Internal Server Error";

        $pstatus = 0;
        if (str_contains($transaction_status, "settlement")) {
            $pstatus = 1;
        }

        $order = Order::where('order_id', $order_id)->first();
        if ($result != null) {
            //asumsi di hit dari button user di web
            if ($order != null) {
                $order->payment_status = $pstatus;
                $order->save();
            } else {
                //new order dan langsung finished paymentnya
                $this->insertToOrder($request);
                $this->insertToOrderProducts($request);
                $this->insertToOrderMidtransDetail($request);
            }
        } else {
            //asumsi di hit otomatis
            if ($order != null) {
                $order->payment_status = $pstatus;
                $order->save();
            }
        }
        
        return response()->json([
            'response' => [
                'statusCode' => $statusCode,
                'message' => $statusMessage
            ],
            'data' => [
                'order_id' => $order_id,
                'order_status_code' => $status_code,
                'transaction_status' => $transaction_status,
                'result'=> $result,
            ]
        ], 200);
    }

    public function callback_unfinished(Request $request) {
        $order_id = $request->order_id;
        $status_code = $request->status_code;
        $transaction_status = $request->transaction_status;
        $result = $request->result;

        //asumsinya di hit otomatis dan di hit by click checkout
        $statusCode = 500;
        $statusMessage = "Internal Server Error";

        $pstatus = 0;
        if (str_contains($transaction_status, "settlement")) {
            $pstatus = 1;
        }

        $order = Order::where('order_id', $order_id)->first();
        if ($result != null) {
            //asumsi di hit dari button user di web
            if ($order != null) {
                $order->payment_status = $pstatus;
                $order->save();
            } else {
                //new order dan langsung finished paymentnya
                $this->insertToOrder($request);
                $this->insertToOrderProducts($request);
            }
        } else {
            //asumsi di hit otomatis
            if ($order != null) {
                $order->payment_status = $pstatus;
                $order->save();
            }
        }
        
        return response()->json([
            'response' => [
                'statusCode' => $statusCode,
                'message' => $statusMessage
            ],
            'data' => [
                'order_id' => $order_id,
                'order_status_code' => $status_code,
                'transaction_status' => $transaction_status,
                'result'=> $result,
            ]
        ], 200);
    }

    public function callback_error(Request $request) {
        $order_id = $request->order_id;
        $status_code = $request->status_code;
        $transaction_status = $request->transaction_status;
        $result = $request->result;

        //asumsinya di hit otomatis dan di hit by click checkout
        $statusCode = 500;
        $statusMessage = "Internal Server Error";
        
        return response()->json([
            'response' => [
                'statusCode' => $statusCode,
                'message' => $statusMessage
            ],
            'data' => [
                'order_id' => $order_id,
                'order_status_code' => $status_code,
                'transaction_status' => $transaction_status,
                'result'=> $result,
            ]
        ], 200);
    }

    public function get_coupon_cost($request_coupon) {
        if($request_coupon){
            $coupon = Coupon::where(['code' => $request_coupon, 'status' => 1])->first();
            if($coupon){
                if($coupon->expired_date >= date('Y-m-d')){
                    if($coupon->apply_qty <  $coupon->max_quantity ){
                        if($coupon->offer_type == 1){
                            $couponAmount = $coupon->discount;
                            $couponAmount = ($couponAmount / 100) * $total_price;
                        }elseif($coupon->offer_type == 2){
                            $couponAmount = $coupon->discount;
                        }
                        $coupon_price = $couponAmount;

                        $qty = $coupon->apply_qty;
                        $qty = $qty +1;
                        $coupon->apply_qty = $qty;
                        $coupon->save();

                        return $coupon_price;

                    }
                }
            }
        }
    }

    public function insertToOrder($request) {
        $order_id = $request->order_id;
        $status_code = $request->status_code;
        $transaction_status = $request->transaction_status;
        $result = $request->result;

        $shipping = Address::with('country','state','city')->where(['id' => $result->user->address_shipping])->first();
        $billing = Address::with('country','state','city')->where(['id' => $result->user->address_billing])->first();
        $qty = ShoppingCart::with('variants')->where('user_id', $result->user->user_id)->sum('qty');

        $order = new Order();
        $order->order_id = $order_id;
        $order->user_id = $result->user->user_id;
        $order->total_amount = $result->payment->gross_amount;
        $order->product_qty = $qty;
        $order->payment_method = 'midtrans';
        $order->transection_id = $result->payment->transaction_id;
        $order->payment_status = $pstatus;
        $order->shipping_method = $shipping->shipping_rule;
        $order->shipping_cost = $shipping->shipping_fee;
        $order->coupon_cost = get_coupon_cost($result->user->coupon);
        $order->order_status = 0;
        $order->cash_on_delivery = 0;
        $order->save();
    }

    public function insertToOrderProducts($request) {
        $order_id = $request->order_id;
        $status_code = $request->status_code;
        $transaction_status = $request->transaction_status;
        $result = $request->result;

        $cartProducts = ShoppingCart::with('product','variants.variantItem')->where('user_id', $request->user->user_id)->select('id','product_id','qty')->get();
        if($cartProducts->count() != 0){
            $order_details = '';
            $setting = Setting::first();
            foreach($cartProducts as $key => $cartProduct){

                $variantPrice = 0;
                if($cartProduct->variants){
                    foreach($cartProduct->variants as $item_index => $var_item){
                        $item = ProductVariantItem::find($var_item->variant_item_id);
                        if($item){
                            $variantPrice += $item->price;
                        }
                    }
                }

                // calculate product price
                $product = Product::select('id','price','offer_price','weight','vendor_id','qty','name')->find($cartProduct->product_id);
                $price = $product->offer_price ? $product->offer_price : $product->price;
                $price = $price + $variantPrice;
                $isFlashSale = FlashSaleProduct::where(['product_id' => $product->id,'status' => 1])->first();
                $today = date('Y-m-d H:i:s');
                if($isFlashSale){
                    $flashSale = FlashSale::first();
                    if($flashSale->status == 1){
                        if($today <= $flashSale->end_time){
                            $offerPrice = ($flashSale->offer / 100) * $price;
                            $price = $price - $offerPrice;
                        }
                    }
                }

                // store ordre product
                $orderProduct = new OrderProduct();
                $orderProduct->order_id = $order_id;
                $orderProduct->product_id = $cartProduct->product_id;
                $orderProduct->seller_id = $product->vendor_id;
                $orderProduct->product_name = $product->name;
                $orderProduct->unit_price = $price;
                $orderProduct->qty = $cartProduct->qty;
                $orderProduct->save();

                // update product stock
                $qty = $product->qty - $cartProduct->qty;
                $product->qty = $qty;
                $product->save();

                // store prouct variant

                // return $cartProduct->variants;
                foreach($cartProduct->variants as $index => $variant){
                    $item = ProductVariantItem::find($variant->variant_item_id);
                    $productVariant = new OrderProductVariant();
                    $productVariant->order_product_id = $orderProduct->id;
                    $productVariant->product_id = $cartProduct->product_id;
                    $productVariant->variant_name = $item->product_variant_name;
                    $productVariant->variant_value = $item->name;
                    $productVariant->save();
                }

                $order_details.='Product: '.$product->name. '<br>';
                $order_details.='Quantity: '. $cartProduct->qty .'<br>';
                $order_details.='Price: '.$setting->currency_icon . $cartProduct->qty * $price .'<br>';

            }

            // store shipping and billing address
            $billing = Address::find($result->user->address_shipping);
            $shipping = Address::find($result->user->address_billing);
            $orderAddress = new OrderAddress();
            $orderAddress->order_id = $order_id;
            $orderAddress->billing_name = $billing->name;
            $orderAddress->billing_email = $billing->email;
            $orderAddress->billing_phone = $billing->phone;
            $orderAddress->billing_address = $billing->address;
            $orderAddress->billing_country = $billing->country->name;
            $orderAddress->billing_state = $billing->state->name;
            $orderAddress->billing_city = $billing->city->name;
            $orderAddress->billing_address_type = $billing->type;
            $orderAddress->shipping_name = $shipping->name;
            $orderAddress->shipping_email = $shipping->email;
            $orderAddress->shipping_phone = $shipping->phone;
            $orderAddress->shipping_address = $shipping->address;
            $orderAddress->shipping_country = $shipping->country->name ;
            $orderAddress->shipping_state = $shipping->state->name;
            $orderAddress->shipping_city = $shipping->city->name;
            $orderAddress->shipping_address_type = $shipping->type;
            $orderAddress->save();

            foreach($cartProducts as $cartProduct){
                ShoppingCartVariant::where('shopping_cart_id', $cartProduct->id)->delete();
                $cartProduct->delete();
            }
        }
    }

    public function insertToOrderMidtransDetail($request) {
        $order_id = $request->order_id;
        $status_code = $request->status_code;
        $transaction_status = $request->transaction_status;
        $result = $request->result;

        $mo = new MidtransOrder();
        $mo->order_id = $order_id;
        $mo->payment_type = $result->payment->payment_type;
        // $mo->payment_info = $result->
        $mo->status_code = $status_code;
        $mo->status_message = $result->payment->status_message;
        $mo->transaction_id = $result->payment->transaction_id;
        $mo->transaction_status = $result->payment->transaction_status;
        $mo->transaction_time = $result->payment->transaction_time;
        // $mo->status = $result->payment->status;
        $mo->save();
    }
}