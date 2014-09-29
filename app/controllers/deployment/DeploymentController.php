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
        $deployments = $this->deployments
            ->select('cloud_accounts.name as accountName', 'cloud_accounts.cloudProvider', 'deployments.name', 'deployments.cloud_account_id', 'deployments.created_at')
            ->leftJoin('cloud_accounts', 'deployments.cloud_account_id', '=', 'cloud_accounts.id')
            ->where('deployments.user_id', Auth::id())
            ->orderBy('deployments.created_at', 'DESC')
            ->paginate(10);
			
			$search_term = Input::get('q');
            if (empty($search_term)) {
                $search_term = 'xdocker';
            }
           
            $response = xDockerEngine::dockerHubGet($search_term);
            
            $dockerInstances = $response->results;
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
            $deployment->cloud_account_id = Input::get('cloud_account_id');
			
            $deployment->parameters = json_encode(Input::get('parameters'));
            $deployment->docker_name = Input::get('docker_name');
            $deployment->user_id = Auth::id(); // logged in user id
            
            
            try {
                // Get and save status from external WS
                $user = Auth::user();
				$responseJson = xDockerEngine::authenticate(array('username' => $user->username, 'password' => md5($user->engine_key)));
				EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'authenticate', 'return' => $responseJson));
				$obj = json_decode($responseJson);
				
				if($obj->status == 'OK')
				{
					$deployment -> token = $obj->token;
					$this->prepare($user, $deployment);
				}
				
				$responseJson = xDockerEngine::run(json_decode($deployment->wsParams));
				EngineLog::logIt(array('user_id' => Auth::id(), 'method' => 'run', 'return' => $responseJson));
				$obj = json_decode($responseJson);
				if($obj->status == 'OK')
				{
					$deployment -> job_id = $obj->job_id;
					$deployment -> status = 'In Progress';
				}
				unset($deployment -> token );
				 //$deployment->status = $status;
	            $success = $deployment->save();
	            if (!$success) {
	            	print_r($deployment->errors());
	            	Log::error('Error while saving deployment : '.json_encode( $deployment->errors()));
	                //throw new Exception($deployment->errors());
	            }
            }
            catch(Exception $err) {
                $status = 'Unexpected Error: ' . $err->getMessage();
				Log::error('Error while saving deployment : '. $status);
                throw new Exception($err->getMessage());
            }
            return Redirect::to('/')->with('success', Lang::get('deployment/deployment.deployment_updated'));
        }
        catch(Exception $e) {
        	Log::error('Error while saving deployment : '. $e->getMessage());
            return Redirect::back()->with('error', $e->getMessage());
        }
    }

	private function prepare($user, & $deployment)
	{
		/* "token": "<token>",
    "secretKey": "<api secret>",
    "packageName": "xdocker/securitymonkey",
    "dockerParams": {"ports": [443, 5000], "env": {}, "tag": "v1"},
    "apiKey": "<api key>",
    "cloudProvider": "amazon"
		 * */
		$account = CloudAccount::where('user_id', Auth::id())->findOrFail($deployment->cloud_account_id) ;
		$credentials = json_decode($account->credentials);
		
		$deployment->wsParams = json_encode(
							array (
						'token' => $deployment->token,
						'username' => $user->username,
						'cloudProvider' => $account ->cloudProvider,
						'apiKey' => $credentials ->apiKey,
						'secretKey' => $credentials ->secretKey,
						'packageName' => $deployment -> docker_name,
						'dockerParams' => array('ports' => array(443,5000), 
												'env' => array('mail' =>$user->email, 'host'=> '{host}'), 
												'tag'=> 'v1')
						)
						);				
	}
    /**
     * Remove the specified Account .
     *
     * @param $deployment
     *
     */
    public function postDelete($id) {
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
}
