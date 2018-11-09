<?php

namespace App\Http\Controllers;

use App\Classes\LogToFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Signal; // Link model
use App\Client; // Link model
use App\Execution; // Link model
use ccxt\bitmex;
use Illuminate\Support\Facades\Cache;
use Mockery\Exception;

class SymbolController extends Controller
{
    private $orderVolume;
    private $exchange;
    private $placeOrderResponse;
    private $symbolInXBT;
    private $symbolQuote;

    public function __construct()
    {
        $this->exchange = new bitmex();
    }

    // add: signal id, client id
    public function executeSymbol(Request $request){

        LogToFile::createTextLogFile();

        // Do it once. Only for a new signal
        if ($request['status'] == "new"){
            $this->fillExecutionsTable($request);
            $this->getClientsFunds($request, $this->exchange);
            $this->fillVolume($request, $this->exchange);
        }

        // Do for both: new and open signals
        foreach (Execution::where('signal_id', $request['id'])
                     ->where('client_volume', '!=', null)
                     ->get() as $execution){

            $this->exchange->apiKey = Client::where('id', $execution->client_id)->value('api');
            $this->exchange->secret = Client::where('id', $execution->client_id)->value('api_secret');

            if($request['status'] == "new"){
                if ($request['direction'] == "long"){
                    $this->openPosition($this->exchange, $execution, "long");
                }
                else{
                    $this->openPosition($this->exchange, $execution, "short");
                }
            }
            else
            {
                if ($request['direction'] == "long"){
                    $this->openPosition($this->exchange, $execution, "short");
                }
                else{
                    $this->openPosition($this->exchange, $execution, "long");
                }
            }
        }


        //return "position closed";
        //return response('No symbol found', 412);

    }

    /**
     * Calculate and fill volume for each client (each record in the table).
     * @param Request $request
     * @param bitmex $exchange
     * @return string
     */
    public function fillVolume(Request $request, bitmex $exchange){


        // Get quote
        try {
            $this->symbolQuote = $this->exchange->fetch_ticker($request['symbol'])['last'];
        } catch (\Exception $e) {
            return $e->getMessage();
        }


        /**
         * Run through all records in executions table
         * With a specific signal id
         * And where client funds != 0. If == 0 it means that API keys did not work and we did not get the balance
         */
        foreach (Execution::where('signal_id', $request['id'])
                     ->where('client_funds', '!=', null)
                     ->get() as $execution){

            // Balance share calculation
            $balancePortionXBT = $execution->client_funds * $execution->percent / 100;
            // Contract formula

            if ($execution->symbol == "ETH/USD")
            {
                // $symbolInXBT = $symbolQuote * $execution->multiplier;
                $this->symbolInXBT = $this->symbolQuote * 0.000001;
            }

            if ($execution->symbol == "BTC/USD")
            {
                $this->symbolInXBT = 1 / $this->symbolQuote;
            }



            // if symbol = XBT/USD
            // $symbolInXBT = 1 / $symbolQuote;

            // Update calculated volume only for records where client funds != null
            // If == null - API key did not work

            Execution::where('signal_id', $request['id'])
                //->where('client_funds', '!=', null)
                ->where('client_id', $execution->client_id)
                // REMOVE ROUND??
                ->update(['client_volume' => round($balancePortionXBT / $this->symbolInXBT), 'status' => 'new', 'info' => 'Volume calculated']);


        }
    }

    /**
     * Run through job list (executions table) and get funds(free XBT balance for each client).
     * @param Request $request
     * @param bitmex $exchange
     */
    public function getClientsFunds(Request $request, bitmex $exchange){
        foreach (Execution::where('signal_id', $request['id'])->get() as $execution){

            $exchange->apiKey = Client::where('id', $execution->client_id)->value('api');
            //$exchange->apiKey = 123;
            $exchange->secret = Client::where('id', $execution->client_id)->value('api_secret');

            try{
                $response = $exchange->fetchBalance()['BTC']['free'];
                Execution::where('signal_id', $request['id'])
                    ->where('client_id', $execution->client_id)
                    ->update(['client_funds' => $response, 'open_response' => 'Got balance ok']);

                LogToFile::add(__FILE__ . __LINE__, $execution->client_id . " :" . $response);

                Client::where('id', $execution->client_id)
                    ->update(['funds' => $response]);
            }
            catch (\Exception $e){
                Execution::where('signal_id', $request['id'])
                    ->where('client_id', $execution->client_id)
                    ->update(['open_response' => 'Error getting client balance', 'info' => $e->getMessage()]);
                //return $e->getMessage();
            }
        }
    }

    /**
     * Fill executions table with a job. A job - symbolize a signal executed on a client account.
     * Clone signal to all clients.
     * Quantity of records = quantity of clients
     * @param Request $request
     */
    private function fillExecutionsTable(Request $request){
        foreach (Client::all() as $client){
            Execution::create([
                'signal_id' => $request['id'],
                'client_id' => $client->id,
                'client_name' => $client->name,
                'symbol' => $request['symbol'],
                'multiplier' => $request['multiplier'],
                'direction' => $request['direction'],
                'percent' => $request['percent'],
                'leverage' => $request['leverage'],
                //'symbol' => $request['percent'],
                //'leverage' => $request['leverage'],
            ]);
        }
    }

    /**
     * Open positions.
     * Performed for each client record in executions table.
     * @param bitmex $exchange
     * @param $direction
     * @param $orderVolume
     */
    private function openPosition(bitmex $exchange, $execution, $direction){

        /* Place order */
        if ($direction == 'long'){
            try{
                $this->placeOrderResponse = $exchange->createMarketBuyOrder($execution->symbol, $execution->client_volume, []);
            }
            catch (\Exception $e){
                $this->placeOrderResponse = $e->getMessage();
            }
        }
        else{
            try{
                $this->placeOrderResponse = $exchange->createMarketSellOrder($execution->symbol, $execution->client_volume, []);
            }
            catch (\Exception $e)
            {
                $this->placeOrderResponse = $e->getMessage();
            }
        }

        // CHECK WHETHER SUCCESS OR NOT!
        if (gettype($this->placeOrderResponse) == 'array'){
            // Success
            LogToFile::add(__FILE__ . __LINE__, "ORDER PLACED SUCCESS: " . gettype($this->placeOrderResponse));
        }
        else{
            // Error
            LogToFile::add(__FILE__ . __LINE__, "ORDER ERROR: " . gettype($this->placeOrderResponse));
        }

        // CATCH BITMEX ERROR INSUFFICIENT FUNDS
        // IS SO - STOP EXECUTION WITH ERROR AND THROW IT TO THE BROWSER


        $updateSignalStatuses = ["status" => "proceeded"];
        $updateExecutionOpenStatuses = [
            'status' => 'open_placed',
            'open_status' => (gettype($this->placeOrderResponse) == 'array' ? 'ok' : 'error'),
            'open_response' => json_encode($this->placeOrderResponse),
            'open_price' => (gettype($this->placeOrderResponse) == 'array' ? $this->placeOrderResponse['price'] : null),
            'info' => ''
        ];

        $updateExecutionCloseStatuses = [
            'status' => 'close_placed',
            'close_status' => (gettype($this->placeOrderResponse) == 'array' ? 'ok' : 'error'),
            'close_response' => json_encode($this->placeOrderResponse),
            'close_price' => (gettype($this->placeOrderResponse) == 'array' ? $this->placeOrderResponse['price'] : null),
            'info' => ''
        ];

        // Write statuses to DB
        // Open
        Signal::where('id', $execution->signal_id)->update($updateSignalStatuses);
        if ($execution->status == "new") {
            Execution::where('id', $execution->id)->update($updateExecutionOpenStatuses);
            Signal::where('id', $execution->signal_id)->update([
                'open_date' => (gettype($this->placeOrderResponse) == 'array' ? date("Y-m-d G:i:s", $this->placeOrderResponse['timestamp'] / 1000) : null),
                'open_price' => (gettype($this->placeOrderResponse) == 'array' ? $this->placeOrderResponse['price'] : null),
                'quote' => (gettype($this->placeOrderResponse) == 'array' ? $this->placeOrderResponse['price'] : null),
            ]);
        }

        // Close
        if ($execution->status == "open_placed"){
            Execution::where('id', $execution->id)->update($updateExecutionCloseStatuses);
            Signal::where('id', $execution->signal_id)->update([
                'status' => 'finished',
                'close_date' => (gettype($this->placeOrderResponse) == 'array' ? date("Y-m-d G:i:s", $this->placeOrderResponse['timestamp'] / 1000) : null),
                'close_price' => (gettype($this->placeOrderResponse) == 'array' ? $this->placeOrderResponse['price'] : null),
                ]);
        }



        /*
        Execution::where('id', $execution->id)->update(array(
            'info' => json_encode($this->placeOrderResponse)
            'close_date' => date("Y-m-d G:i:s", $this->placeOrderResponse['timestamp'] / 1000),
            'close_price' => $this->placeOrderResponse['price']
        ));
        */





    }

}


/*
        $exchange = new bitmex();
        //dump(array_keys($exchange->load_markets())); // ETH/USD BTC/USD
        //dump($exchange->fetch_ticker('BTC/USD'));
        $exchange->apiKey = Client::where('id', '>', 0)->orderby('id', 'desc')->first()->api;
        $exchange->secret = Client::where('id', '>', 0)->orderby('id', 'desc')->first()->api_secret;
*/