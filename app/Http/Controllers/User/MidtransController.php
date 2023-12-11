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
use App\Models\ShoppingCart;
use App\Models\Coupon;
use App\Models\Shipping;
use App\Models\MyfatoorahPayment;
use App\Models\Order;
use Cart;
use Str;
use Session;
class MidtransController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except('callback_finished','callback_unfinished','callback_error');
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
                'orderid_next' => $id . "" . $number
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
            // 'items' => $item_details,
            // 'addr_shipping' => $address_shipping,
            // 'addr_billing' => $address_billing,
            // 'rule' => $rule
        ], 200);
    }

    public function post_order(Request $request) {
        $statusCode = 500;
        $statusMessage = "Internal Server Error";

        $user = Auth::guard('api')->user();
        $order = Order::where('order_id', $request->order_id)->first();
        if ($order != null) {
            $statusCode = 200;
            $statusMessage = json_encode($order);

            //update order in db
        } else {
            //insert to db
            $order = new Order();
            $order->order_id = $request->order_id;
            $order->order_status = 0;
            $order->cash_on_delivery = 0;
            $order->transection_id = "midtrans";
            $order->payment_status = $pstatus;

            $statusCode = 200;
            $statusMessage = json_encode($order);
        }
        $order->save();
        
        return response()->json([
            'status_code' => $statusCode,
            'message' => $statusMessage
        ], $statusCode);
    }

    public function callback_finished(Request $request) {
        // $cartProducts = ShoppingCart::with('product','variants.variantItem')->where('user_id', $user->id)->select('id','product_id','qty')->get();
        $statusCode = 500;
        $statusMessage = "Internal Server Error";
        $order = Order::where('order_id', $request->order_id)->first();

        $pstatus = 0;
        $tstatus = $request->transaction_status;
        if (str_contains($tstatus, "settlement")) {
            $pstatus = 1;
        }

        if ($order != null) {
            $statusCode = 200;
            $statusMessage = json_encode($order);

            $order->payment_status = $pstatus;
        } else {
            //insert to db
            $order = new Order();
            $order->order_id = $request->order_id;
            $order->order_status = 0;
            $order->cash_on_delivery = 0;
            $order->transection_id = "midtrans";
            $order->payment_status = $pstatus;

            $statusCode = 200;
            $statusMessage = json_encode($order);
        }
        $order->save();

        return response()->json([
            'request' => [
                'status_code' => $request->status_code,
                'order_id' => $request->order_id,
                'transaction_status' => $request->transaction_status
            ],
            'status_code' => $statusCode,
            'message' => $statusMessage
        ], $statusCode);
    }

    public function callback_unfinished(Request $request) {
        $statusCode = 500;
        $statusMessage = "Internal Server Error";
        $order = Order::where('order_id', $request->order_id)->first();

        if ($order != null) {
            $statusCode = 200;
            $statusMessage = json_encode($order);
        } else {
            //insert to db

        }

        return response()->json([
            'data' => [
                'order_id' => $request->order_id,
            ],
            'status_code' => $statusCode,
            'message' => $statusMessage
        ], $statusCode);
    }

    public function callback_error(Request $request) {
        $statusCode = 500;
        $statusMessage = "Internal Server Error";
        return response()->json([
            'data' => [
                'order_id' => $request->order_id,
            ],
            'status_code' => $statusCode,
            'message' => $statusMessage
        ], $statusCode);
    }
}