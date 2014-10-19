<?php
/**
 * Class and Function List:
 * Function list:
 * - __construct()
 * - getIndex()
 * - getCreate()
 * - postEdit()
 * - postDelete()
 * Classes list:
 * - DeploymentController extends BaseController
 */
class DeploymentController extends BaseController {
    /**
     * Deployment Model
     * @var deployments
     */
    protected $deployments;
    /**
     * User Model
     * @var User
     */
    protected $user;
    /**
     * Inject the models.
     * @param Deployment $deployments
     * @param User $user
     */
    public function __construct(Deployment $deployments, User $user) {
        parent::__construct();
        $this->deployments = $deployments->where('deployments.user_id', Auth::id());
        $this->user = $user;
    }
    
    public function getIndex() {
        // Get all the user's deployment
        $deployments = DeploymentQueryHelper::getQuery( $this->deployments, 10 );
			
			$search_term = Input::get('q');
            if (empty($search_term)) {
                $search_term = 'xdocker';
            }
           
            $response = xDockerEngine::dockerHubGet($search_term);
            
			
            $dockerInstances = !empty($response) ? $response->results : '';
        // var_dump($accounts, $this->accounts, $this->accounts->owner);
        // Show the page
        return View::make('site/deployment/index', array(
            'deployments' => $deployments,
            'search_term' => $search_term,
            'dockerInstances' => $dockerInstances,
        ));
    }
    /**
     * Displays the form for cloud deployment creation
     *
     */
    public function getCreate($id = false) {
        $mode = $id !== false ? 'edit' : 'create';
        $deployment = $id !== false ? Deployment::where('user_id', Auth::id())->findOrFail($id) : null;
        $cloud_account_ids = CloudAccount::where('user_id', Auth::id())->get();
        if (empty($cloud_account_ids) || $cloud_account_ids->isEmpty()) {
            return Redirect::to('account/create')->with('error', Lang::get('deployment/deployment.account_required'));
        }
		$providers = Config::get('deployment_schema');
		return View::make('site/deployment/create', array(
            'mode' => $mode,
            'deployment' => $deployment,
            'providers' => $providers,
            'cloud_account_ids' => $cloud_account_ids,
            'docker_name' => Input::get('name')
        ));
    }
    /**
     * Saves/Edits an deployment
     *
     */
    public function postEdit($deployment = false) {
        try {
            if (empty($deployment)) {
                $deployment = new Deployment;
            } else if ($deployment->user_id !== Auth::id()) {
                throw new Exception('general.access_denied');
            }
            $deployment->name = Input::get('name');
            $deployment->cloudAccountId = Input::get('cloudAccountId');
			$params = Input::get('parameters');
			//$params['instanceImage'] = Input::get('instanceImage');
			$arr = explode(':', Input::get('instanceAmi'));
			$params['instanceAmi'] = $arr[1];
			$params['OS'] = $arr[0];
			$deployment->parameters = json_encode($params);
            $deployment->docker_name = Input::get('docker_name');
            $deployment->user_id = Auth::id(); // logged in user id
            
            
            try {
                // Get and save status from external WS
                $user = Auth::user();
				$responseJson = xDockerEngine::authenticate(array('username' => $user->username, 'password' => md5($user->engine_key)));
				EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'authenticate', 'return' => $responseJson));
				$obj = json_decode($responseJson);
				
				if(!empty($obj) && $obj->status == 'OK')
				{
					$deployment -> token = $obj->token;
					$this->prepare($user, $deployment);
					$responseJson = xDockerEngine::run(json_decode($deployment->wsParams));
					EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'run', 'return' => $responseJson));
					$obj1 = json_decode($responseJson);
					if(!empty($obj1) && $obj1->status == 'OK')
					{
						$deployment -> job_id = $obj1->job_id;
						$deployment -> status = Lang::get('deployment/deployment.status');
						unset($deployment -> token );
						 //$deployment->status = $status;
		            	$success = $deployment->save();
		            	if (!$success) {
		            		Log::error('Error while saving deployment : '.json_encode( $deployment->errors()));
							return Redirect::to('deployment')->with('error', 'Error saving deployment!' );
		                //throw new Exception($deployment->errors());
						}
					}
					else if(!empty($obj1) && $obj1->status == 'error')
					{
						Log::error('Failed during deployment!'. $obj1->message);
						return Redirect::to('deployment')->with('error', 'Failed during deployment!'. $obj1->message);
					}
					return Redirect::to('deployment')->with('success', Lang::get('deployment/deployment.deployment_updated'));
	            }
				else if(!empty($obj) && $obj->status == 'error')
				{
					Log::error('Failed to authenticate before deployment!'. $obj->message);
					return Redirect::to('deployment')->with('error', 'Failed to authenticate before deployment!'. $obj->message);
				}
				else
				{
					Log::error('error', 'Unexpected error - Backend Engine API should be down!');
					return Redirect::to('ServiceStatus')->with('error', 'Backend API is down, please try again later!');		
				}
 
            }
            catch(Exception $err) {
                $status = 'Unexpected Error: ' . $err->getMessage();
				Log::error('Error while saving deployment : '. $status);
                throw new Exception($err->getMessage());
            }
            
        }
        catch(Exception $e) {
        	Log::error('Error while saving deployment : '. $e->getMessage());
            return Redirect::back()->with('error', $e->getMessage());
        }
    }

	private function prepare($user, & $deployment)
	{
		$account = CloudAccount::where('user_id', Auth::id())->findOrFail($deployment->cloudAccountId) ;
		$credentials = json_decode($account->credentials);
		$parameters = json_decode($deployment->parameters);
		$dockerParams = xDockerEngine::getDockerParams($deployment -> docker_name);
		if($dockerParams['env_keys'])
		{
			$keys = array('AWS_ACCESS_KEY_ID'     => $credentials ->apiKey,
					      'AWS_SECRET_ACCESS_KEY' => $credentials ->secretKey,
					      'BILLING_BUCKET'        => 
					      				!empty($credentials ->billingBucket) ? $credentials ->billingBucket : '');
		
			$dockerParams['env'] = array_merge($dockerParams['env'], $keys);
		}
		$secPolicy = xDockerEngine::securityPolicy($deployment -> docker_name) ;
		if(!empty($secPolicy))
		{
			$keys = array_keys($secPolicy);
		}
		else {
			$keys[0] = 0;	
			$secPolicy[0] ='';
		}
		$keys = !empty($secPolicy) ? array_keys($secPolicy) : '';
		
		$deployment->wsParams = json_encode(
                                    array (
                                        'token' => $deployment->token,
                                        'username' => $user->username,
                                        'cloudProvider' => $account ->cloudProvider,
                                        'apiKey' => $credentials ->apiKey,
                                        'secretKey' => $credentials ->secretKey,
                                        'billingBucket' => !empty($credentials ->billingBucket) ? $credentials ->billingBucket : '' ,
                                        'instanceName' => $deployment->name,
                                        'instanceType' => $parameters->instanceType,
                                        'instanceRegion' => $parameters->instanceRegion,
                                        'instanceAmi' => $parameters->instanceAmi,
                                        'OS' => $parameters->OS,
                                        'packageName' => $deployment -> docker_name,
                                        'dockerParams' => $dockerParams,
                                        $keys[0] => $secPolicy[$keys[0]]
										)
                                      );	
		  				
	}

	/**
     * Remove the specified Account .
     *
     * @param $deployment
     *
     */
    public function postDelete($id) 
    {
    	$this->terminateInstance($id);
    	Deployment::where('id', $id)->where('user_id', Auth::id())->delete();
		
        // Was the comment post deleted?
        $deployment = Deployment::where('user_id', Auth::id())->find($id);
        if (empty($deployment)) {
            // TODO needs to delete all of that user's content
            return Redirect::to('/')->with('success', 'Removed Deployment Successfully');
        } else {
            // There was a problem deleting the user
            return Redirect::to('/')->with('error', 'Error while deleting');
        }
    }

	private function terminateInstance($id)
	{
		$deployment = Deployment::where('user_id', Auth::id())->find($id);
		$account = CloudAccount::where('user_id', Auth::id())->findOrFail($deployment->cloudAccountId) ;
		$instanceId =  Input::get('instanceID');
		Log::error('Terminating Instance :'. $instanceId);
		$response = $this->executeAction(Input::get('instanceAction'), $account, $deployment, $instanceId);
		if($response['status'] == 'OK') return TRUE;
		else return FALSE;
			
	}
	
	public function checkStatus($id)
	{
		$deployment = Deployment::where('user_id', Auth::id())-> whereNotIn('status', array('Completed'))->find($id);
		if(empty($deployment))
		{
			return Redirect::to('deployment')->with('info', 'Selected deployment do not need refresh!');
		}
		$user = Auth::user();
		$responseJson = xDockerEngine::authenticate(array('username' => $user->username, 'password' => md5($user->engine_key)));
		 EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'authenticate', 'return' => $responseJson));
		 $obj = json_decode($responseJson);
		
		 if(!empty($obj) && $obj->status == 'OK')
		 {
			$responseJson = xDockerEngine::getDeploymentStatus(array('token' => $obj->token, 'job_id' => $deployment->job_id));
			EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'getDeploymentStatus', 'return' => $responseJson));
		
			$obj2 = json_decode($responseJson);
			if(!empty($obj2) && $obj2->status == 'OK')
			{
				if(!isset($obj2 -> result))
				{
					Log::error('No Result in the checkStatus Request to be saved!');
					$obj2 -> result ='';
				} 
				$deployment->status = $obj2->job_status;
				$deployment -> wsResults = json_encode($obj2 -> result);
				$success = $deployment->save();
		        if (!$success) {
		        	Log::error('Error while saving deployment : '.json_encode( $dep->errors()));
					return Redirect::to('deployment')->with('error', 'Error saving deployment!' );
		        }
				return Redirect::to('deployment')->with('success', $deployment->name .' is refreshed' );
			}
			else  if(!empty($obj2) && $obj2->status == 'error')
			 {
				 // There was a problem deleting the user
	            return Redirect::to('deployment')->with('error', $obj2->message );
			 }	
			else
			{
				  return Redirect::to('ServiceStatus')->with('error', 'Backend API is down, please try again later!');			
			}
			
		 }	
		 else if(!empty($obj) && $obj->status == 'error')
		 {
			 // There was a problem deleting the user
            return Redirect::to('deployment')->with('error', $obj->message );
		 }	
		 else
		 {
			return Redirect::to('ServiceStatus')->with('error', 'Backend API is down, please try again later!');			
		 }	
	}

	public function getLogs($id)
	{
		$deployment = Deployment::where('user_id', Auth::id())->find($id);
		
		if(!empty($deployment) && isset($deployment->job_id))
		{
			$responseJson = xDockerEngine::authenticate(array('username' => Auth::user()->username, 'password' => md5(Auth::user()->engine_key)));
		 	EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'authenticate', 'return' => $responseJson));
		 	$obj = json_decode($responseJson);
			
			if(!empty($obj) && $obj->status == 'OK')
		 	{
				$response = xDockerEngine::getLog(array('token' => $obj->token, 'job_id' => $deployment->job_id, "line_num"> 10));
				return View::make('site/deployment/logs', array(
            	'response' => $response,
            	'deployment' => $deployment));
				
			}
			else if(!empty($obj) && $obj->status == 'error')
			{
				 return Redirect::to('deployment')->with('error', $obj->message );
			}
			else
				{
					return Redirect::to('ServiceStatus')->with('error', 'Backend API is down, please try again later!');
				}
		}
		else {
			 return Redirect::to('deployment')->with('info', 'No Log found '. isset($deployment->name) ? $deployment->name : '' );
		}
		
	}
	
	public function postInstanceAction($id)
	{
		$instanceAction = Input::get('instanceAction');
		$instanceID 	= Input::get('instanceID');
		$deployment 	= Deployment::where('user_id', Auth::id())->find($id);
		$account 		= CloudAccount::where('user_id', Auth::id())->findOrFail($deployment->cloudAccountId) ;
		$credentials 	= json_decode($account->credentials);
		
		$result			= json_decode($deployment->wsResults);
		$arr = $this->executeAction($instanceAction, $account, $deployment, $instanceID);
										
		if($arr['status'] == 'OK')
		{
			$deployment->status = (!in_array($instanceAction, array('describeInstances'))) ? $instanceAction : $deployment->status;
			$success = $deployment->save();
		    if (!$success) 
		    {
		    	Log::error('Error while saving deployment -  : '.$instanceAction.' '.json_encode( $arr['message']));
				print json_encode(array('status' => 'error', 'message' => $arr['message']));
		    }
					//return Redirect::to('deployment')->with('success', $deployment->name .' : '.$instanceAction.' submitted! ' );
			print json_encode(array('status' => 'OK', 'message' =>  $arr['message'] ));
		}
		else if($arr['status'] == 'error')
		{
			Log::error('Error occured - while submitting '. $instanceAction .' request');
					//return Redirect::to('deployment')->with('error', 'Error while submitting  '.$instanceAction .'request ' );
			print json_encode(array('status' => 'error', 'message' => 'Error while submitting '.$instanceAction .' request ' ));
		}
		
	}

	public function postDownloadKey($id)
	{
		$instanceID 	= Input::get('instanceID');
		$deployment 	= Deployment::where('user_id', Auth::id())->find($id);
		$account 		= CloudAccount::where('user_id', Auth::id())->findOrFail($deployment->cloudAccountId) ;
		$credentials 	= json_decode($account->credentials);
		
		$result			= json_decode($deployment->wsResults);
		$arr = $this->executeAction('downloadKey', $account, $deployment, $instanceID);
		
		print_r($arr);
	}

	private function executeAction($instanceAction, $account, $deployment , $instanceID)
	{
		$param 			= json_decode($deployment->parameters);
		$account -> instanceRegion =  $param->instanceRegion;
		return CloudProvider::executeAction($instanceAction, $account, $deployment, $instanceID);
	}
	
	public function getImages()
	{
		$cloudProvider = Input::get('cloudProvider');
		$region = Input::get('region');
		$images = Config::get('images');
		foreach($images as $provider => $image)
		{
			if($provider == $cloudProvider)
			{
				foreach($image as $key => $val)
                {
                   if($key == $region)
                   {
                    	echo json_encode($val);
                   }                     
                }


			}
		}
	}

	public function getPrices()
	{
		$cloudProvider = Input::get('cloudProvider');
		$param = new stdClass();
		$param->region 		 = Input::get('region');
		$param->instanceType = Input::get('instanceType');
		
		$ondemand = EC2InstancePrices::OnDemand2($param);
		
		echo json_encode($ondemand);
		
		//echo json_encode(EC2InstancePrices::On)
	}

	
}
