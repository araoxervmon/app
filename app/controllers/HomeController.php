<?php
/**
 * Class and Function List:
 * Function list:
 * - __construct()
 * - getIndex()
 * Classes list:
 * - HomeController extends BaseController
 */
class HomeController extends BaseController {
    /**
     * Post Model
     * @var Post
     */
     protected $deployments;
    /**
     * User Model
     * @var User
     */
    protected $user;
    /**
     * Inject the models.
     * @param Post $post
     * @param User $user
     */
    public function __construct(Deployment $deployment, User $user) {
        parent::__construct();
        $this->deployments = $deployment;
        $this->user = $user;
    }
    /**
     * Returns all the blog posts.
     *
     * @return View
     */
    public function getIndex() {
        if (Auth::check()) {
            //$deployments = Deployment::where('user_id', Auth::id())->get();
			 $deployments = $this->deployments
            ->select('deployments.id', 'cloud_accounts.name as accountName', 
            		 'cloud_accounts.cloudProvider', 'deployments.name', 
            		 'deployments.docker_name', 
            		 'deployments.cloud_account_id', 'deployments.status', 
            		 'deployments.wsResults',
            		 'deployments.created_at')
            ->leftJoin('cloud_accounts', 'deployments.cloud_account_id', '=', 'cloud_accounts.id')
            ->where('deployments.user_id', Auth::id())
            ->orderBy('deployments.created_at', 'DESC')
            ->paginate(10);
        } else {
            $deployments = array();
        }
        try {
            $search_term = Input::get('q');
            if (empty($search_term)) {
                $search_term = 'xdocker';
            }
            
            $response = xDockerEngine::dockerHubGet($search_term);
            
            $dockerInstances = $response->results;
            // var_dump($dockerHubCredentials, $dockerInstances, json_decode($dockerInstances));
        }
        catch(Exception $e) {
            Log::error('Exception while loading docker images!');
            $dockerInstances = array();
        }
        // Show the page
        return View::make('site/home/index', array(
            'deployments' => $deployments,
            'search_term' => $search_term,
            'dockerInstances' => $dockerInstances
        ));
    }
}

