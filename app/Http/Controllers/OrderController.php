<?php

namespace App\Http\Controllers;

use App\Order;
use Illuminate\Http\Request;
use Dnetix\Redirection\PlacetoPay;

class OrderController extends Controller
{
    public function create()
    {
        return view('orders.create');
    }
    
    public function store(Request $request)
    {
        $request = $request->validate([
            'name' => ['required','max:80'],
            'email' => ['required','email','max:120'],
            'mobile' => ['required','regex:/^[0-9\-\(\)\/\+\s]*$/','max:40'],
        ]);

        $order = new Order([
            'customer_name' => $request['name'],
            'customer_email' => $request['email'],
            'customer_mobile' => $request['mobile'],
            'status' => 'CREATED'
        ]);

        $order->save();
                
        return redirect('/order/'.$order->id);
    }  

    public function show(Order $order)
    {        
        if ($order->status == 'CREATED'){            
            if ($order->requests->isEmpty()) {
                return view('orders.preview', compact('order'));
            } else{
                echo('hay requests pendientes');
            }            
        }else{
            return view('orders.show', compact('order'));
        }
        
    }

    public function processPayment(Order $order)
    {
        // If order has been recently created or still pending
        if($order->status == 'CREATED'){
            //Check if there are previous requests who might be still valid.
            if (!($order->requests->isEmpty())){
                $lastreq = $order->requests->last();
                //if last request already expired, make a new request. Else, continue with previous request.
                if($lastreq->expiration > date('c')){
                    return redirect($lastreq->process_url);
                }
            }            
            
            $placetopay = new PlacetoPay([
                'login' => env('PAY_LOGIN', false),
                'tranKey' => env('PAY_TRANKEY', false),
                'url' => 'https://dev.placetopay.com/redirection/',
            ]);
            
            $reference = 'TEST_' . time();
            $expirationtime = date('c', strtotime('+1 hour'));
            
            $request = [
                "locale" => "es_CO",            
                "buyer" => [
                    "name" => $order->customer_name,                
                    "email" => $order->customer_email,                
                    "mobile" => $order->customer_mobile,                
                ],
                "payment" => [
                    "reference" => $reference,
                    "description" => "Iusto sit et voluptatem.",
                    "amount" => [
                        "currency" => "COP",
                        "total" => 183000
                    ],                            
                    "allowPartial" => false
                ],
                "expiration" => $expirationtime,
                "ipAddress" => request()->getHost(),
                "returnUrl" => 'http://127.0.0.1:8000/order/processPayment/'.$order->id,
                "skipResult" => false,
                "noBuyerFill" => false,
                "captureAddress" => false,
                "paymentMethod" => null
            ];
            
            // validar si no tiene requests pendientes
            try {            
                $response = $placetopay->request($request);
            
                if ($response->isSuccessful()) {
                    // Redirect the client to the processUrl or display it on the JS extension                
                    //$order->request_id = $response->requestId();

                    $data = [
                        'order_id' => $order->id,
                        'request_id' => $response->requestId(),                    
                        'process_url' => $response->processUrl(),
                        'status' => $response->status()->status(),
                        'expiration' => $request['expiration']
                    ];
                    $order->requests()->create($data);

                    return redirect($response->processUrl());             
                } else {
                    // There was some error so check the message
                    // $response->status()->message();
                    return view('error', compact('response'));
                }
                var_dump($response);
            } catch (Exception $e) {
                var_dump($e->getMessage());
            }

        }
        
    }

    public function requestInfo(Order $order)
    {
        $lastreq = $order->requests->last();

        $placetopay = new PlacetoPay([
            'login' => env('PAY_LOGIN', false),
            'tranKey' => env('PAY_TRANKEY', false),
            'url' => 'https://dev.placetopay.com/redirection/',
        ]);

        $response = $placetopay->query($lastreq->request_id);

        if ($response->isSuccessful()) {
            // In order to use the functions please refer to the Dnetix\Redirection\Message\RedirectInformation class
        
            if ($response->status()->isApproved()) {
                // The payment has been approved
                $order->status = 'PAYED';
                $order->save();                
            }else{
                $order->status = 'REJECTED';
                $order->save();                
            }
            return view('orders.show', compact('order'));
        } else {
            // There was some error with the connection so check the message
            print_r($response->status()->message() . "\n");
        }
        
    }
}