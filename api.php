<?php

	require_once("rest.php");
	
	class API extends REST {
	
		public $data = "";
        private $_currency_import_url = 'http://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
        private $precision = 2;
        private $_api_token = 'Eq57dwypZaFW4f2xxRzFaGjwCYinOn6l13Mvds00P2ZzgdMPTa';
		
		const DB_SERVER = "localhost";
		const DB_USER = "root";
		const DB_PASSWORD = "root";
		const DB = "rest_api";
        const TABLE = "change_currencie";
		
		private $db = NULL;
	
		public function __construct(){
			parent::__construct();
			$this->dbConnect();
            $this->authorization();

		}

		private function dbConnect(){
			$this->db = mysql_connect(self::DB_SERVER,self::DB_USER,self::DB_PASSWORD);
			if($this->db)
				mysql_select_db(self::DB,$this->db);
		}

		public function processApi(){
			$func = strtolower(trim(str_replace("/","",$_REQUEST['rquest'])));
			if((int)method_exists($this,$func) > 0)
				$this->$func();
			else
				$this->response('',404);
		}
		
		private function authorization(){
            if(!isset($_SERVER['HTTP_API_TOKEN']) || ($_SERVER['HTTP_API_TOKEN'] != $this->_api_token)) {
                $this->response('', 401);
            }
		}

        private function import_data(){
            if (($response_xml_data = file_get_contents($this->_currency_import_url))===false){
                $this->response($this->json('Error fetching XML'), 409);
            } else {
                libxml_use_internal_errors(true);
                $data = simplexml_load_string($response_xml_data);
                if (!$data) {
                    $xml_errors = [];
                    foreach(libxml_get_errors() as $error) {
                        $xml_errors[] =  $error->message;
                    }
                    $this->response($this->json($xml_errors), 409);
                } else {
                    $insert_data = '';
                    foreach($data->Cube->Cube->Cube as $value) {
                        $insert_data .= '(\''.$value->attributes()->currency.'\', '.$value->attributes()->rate.', \''.$data->Cube->Cube->attributes()->time.'\'),';
                    }
                    $insert_data = rtrim($insert_data, ",");
                    $sql = mysql_query("INSERT INTO ".self::TABLE." (currency_code, conversion_value, effective_date) VALUES $insert_data", $this->db);
                    $this->response($this->json(['success' => 'XML data inserted into database']), 200);
                }
            }
        }

        private function convert() {
            if($this->get_request_method() != "GET" && !isset($this->_request['CURRENCY_FROM']) && !isset($this->_request['CURRENCY_TO'])){
                $this->response('',406);
            } else {
                $this->_request['CURRENCY_FROM'] and $amount = $this->_request['AMOUNT'];
                $date = $this->_request['DATE'] ? $this->_request['DATE'] : false;
                $rates = $this->getRatesFromDB($date);
                $rate = round(($amount/$rates[$this->_request['CURRENCY_FROM']])*$rates[$this->_request['CURRENCY_TO']], $this->precision);
                $this->response($this->json(['value' => $rate, 'currency' => $this->_request['CURRENCY_TO']]), 200);
            }
        }

        private function getRatesFromDB($date=false) {
            if($date) {
                $sql = mysql_query("SELECT currency_code, conversion_value FROM ".self::TABLE." WHERE effective_date = '".$date."'", $this->db);
            } else {
                $sql = mysql_query("SELECT currency_code, conversion_value FROM ".self::TABLE." GROUP BY currency_code order by effective_date DESC", $this->db);
            }
            if(mysql_num_rows($sql) > 0){
                $result = array();
                while($rlt = mysql_fetch_array($sql,MYSQL_ASSOC)){
                    $result[$rlt['currency_code']] = $rlt['conversion_value'];
                }
                $result['EUR'] = 1;
                return $result;
            }
            $this->response('',204);
        }

		private function json($data){
			if(is_array($data)){
				return json_encode($data);
			}
		}
	}
	
	$api = new API;
	$api->processApi();
?>