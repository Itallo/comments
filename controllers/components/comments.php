<?php
/* SVN FILE: $Id: comments.php 1470 2009-10-23 14:11:35Z skie $ */
class NoMethodException extends Exception {}


/**
	most of component methods possible to override in controller
	for it need to create method with prefix _comments
	Ex. : _add -> _commentsAdd, _fetchData -> _commentsFetchData
	Callbacksalso need to prefix with '_comments' in controller.

 callbacks
	afterAdd

 params
	comment
	comment_view_type
	comment_action

/**
 * CommentsComponent
 *
 * Helps handle 'view' action of controller so it can list/add related comments.
 * In related controller action there is no need to fetch associated data for comments - this
 * component is fetching them separately (needed different result from model in dependency of
 * used displayType).
 *
 * Needs Router::connectNamed(array('comment', 'comment_view', 'comment_Action)) in config/routes.php.
 *
 * It is also usable to define (in controller, to not fetch unnecessary data
 * in used Controller::paginate() method):
 * var $paginate = array('Comment' => array(
 *	'order' => array('Comment.created' => 'desc'),
 *	'recursive' => 0,
 *	'limit' => 10
 * ));
 *
 * Includes helpers TextWidget and CommentWidget for controller, uses method
 * AppController::blackHole().
 *
 * @see CommentWidgetHelper
 */
class CommentsComponent extends Object {
/**
 * Components
 *
 * @var array $components
 * @access public
 */
	public $components = array('Cookie', 'Session', 'Auth', 'Utils.Utils');
/**
 * Enabled
 *
 * @var boolean $enabled
 * @access public
 */
	public $enabled = true;
/**
 * Controller
 *
 * @var mixed $controller
 * @access public
 */
	public $controller = null;
/**
 * Name of actions this component should use
 *
 * Customizable in beforeFilter()
 *
 * @var array $actionNames
 * @access public
 */
	public $actionNames = array('view', 'comments');
/**
 * Actions used for deleting of some model record, which doesn't use SoftDelete
 * (so we want comments delete directly)
 *
 * Causes than Comment association will NOT be automatically unbind()ed,
 * independently on $this->unbindAssoc
 *
 * Customizable in beforeFilter()
 *
 * @var array $deleteActions
 * @access public
 */
	public $deleteActions = array();
/**
 * Name of 'commentable' model
 *
 * Customizable in beforeFilter(), or default controller's model name is used
 */
	public $modelName = null;
/**
 * Name of association for comments
 *
 * Customizable in beforeFilter()
 */
	public $assocName = 'Comment';
/**
 * Name of user model associated to comment
 *
 * Customizable in beforeFilter()
 */
	public $userModel = 'UserModel';
/**
 * Flag if this component should permanently unbind association to Comment model in order to not
 * query model for not necessary data in Controller::view() action
 *
 * Customizable in beforeFilter()
 */
	public $unbindAssoc = false;
/**
 * Parameters passed to view
 *
 */
	public $commentParams = array();
/**
 * Name of view variable which contains model data for view() action
 *
 * Needed just for PK value available in it
 *
 * Customizable in beforeFilter(), or default Inflector::variable($this->modelName)
 */
	public $viewVariable = null;
/**
 * Name of view variable for comments data
 *
 * Customizable in beforeFilter()
 */
	public $viewComments = 'commentsData';
/**
 * Flag to allow anonymous user make comments
 *
 * Customizable in beforeFilter()
 */
	public $allowAnonymousComment = false;
/**
 * Flag to allow anonymous user make comments
 *
 * Customizable in beforeFilter()
 */
	protected $_supportNamedParams = array('comment', 'comment_action', 'comment_view_type');
/**
 * Callback
 */
	public function initialize(&$controller) {
		$this->controller =& $controller;
		$this->modelName = $controller->modelClass;
		$this->viewVariable = Inflector::variable($this->modelName);
		$controller->helpers = array_merge($controller->helpers, array('Comments.CommentWidget'));
		if (!$controller->{$this->modelName}->Behaviors->attached('Commentable')) {
			$controller->{$this->modelName}->Behaviors->attach('Comments.Commentable');
		}
	}
/**
 * Callback
 */
	public function startup(&$controller) {
		$this->Auth = $this->controller->Auth;
		if ($this->Auth->user()) {
			$controller->set('isAuthorized', ($this->Auth->user('id') != ''));
		}
		if (in_array($controller->action, $this->deleteActions)) {
			$controller->{$this->modelName}->{$this->assocName}->softDelete(false);
		} elseif ($this->unbindAssoc) {
			foreach (array('hasMany', 'hasOne') as $assocType) {
				if (array_key_exists($this->assocName, $controller->{$this->modelName}->{$assocType})) {
					$controller->{$this->modelName}->unbindModel(array($assocType => array($this->assocName)), false);
					break;
				}
			}
		}
	}
/**
 * Callback
 */
	public function beforeRender() {
		try {
			if ($this->enabled && in_array($this->controller->action, $this->actionNames)) {
				$type = $this->_call('initType');
				$this->commentParams = array_merge($this->commentParams, array('displayType' => $type));
				$this->_call('view', array($type));
				$this->_call('prepareParams');
				$this->controller->set('commentParams', $this->commentParams);
			}
		} catch (BlackHoleException $exception) {
			return $this->controller->blackHole($exception->getMessage());
		} catch (NoActionException $exception) {
		}
	}
/**
 * Determine used type of display (flat/threaded/tree)
 */
	public function callback_initType() {
		$types = array('flat', 'threaded', 'tree');
		$param = 'Comments.' . $this->modelName;

		if (!empty($this->controller->passedArgs['comment_view_type'])) {
			$type = $this->controller->passedArgs['comment_view_type'];
			if (in_array($type, $types)) {
				$this->Cookie->write($param, $type, true, '+2 weeks');
				return $type;
			}
		}
		$type = $this->Cookie->read($param);
		if ($type) {
			if (in_array($type, $types)) {
				return $type;
			} else {
				$this->Cookie->delete('Comments');
			}
		}
		return 'flat';
	}
/**
 * Non view action process method
 *
 * @param array
 * @return boolean
 * @access protected
 */
	protected function _processActions($options) {
		extract($options);
		if (isset($this->controller->passedArgs['comment'])) {
			if ($this->allowAnonymousComment || $this->Auth->user()) {
				if (isset($this->controller->passedArgs['comment_action'])) {
					$commentAction = $this->controller->passedArgs['comment_action'];
					$isAdmin = (bool) $this->Auth->user('admin');
					if (!$isAdmin) {
						if (in_array($commentAction, array('delete'))) {
							call_user_func(array(&$this, '_' . Inflector::variable($commentAction)), $id, $this->controller->passedArgs['comment']);
							return;
						} else {
							return $this->controller->blackHole("CommentsComponent: comment_Action '$commentAction' is for admins only");
						}
					}
					if (!in_array($commentAction, array('toggle_approve', 'delete'))) {
						return $this->controller->blackHole("CommentsComponent: unsupported comment_Action '$commentAction'");
					}
					call_user_func(array(&$this, '_' . Inflector::variable($commentAction)), $id, $this->controller->passedArgs['comment']);
				} else {
					Configure::write('Comment.action', 'add');
					$this->_call('add', array($id, $this->controller->passedArgs['comment'], $displayType));
				}
			} else {
				return $this->controller->blackHole('CommentsComponent: user should be logged in for working with comments');
			}
		}
	}
/**
 * Handle controllers action like list/add related comments
 *
 * @param string $displayType
 */
	public function callback_view($displayType) {
		if (!isset($this->controller->{$this->modelName}) || !isset($this->controller->{$this->modelName}->{$this->assocName})) {
			throw new Exception('CommentsComponent: model '.$this->modelName.' or association '.$this->assocName.' doesn\'t exist');
		}

		$primaryKey = $this->controller->{$this->modelName}->primaryKey;

		if (empty($this->controller->viewVars[$this->viewVariable][$this->modelName][$primaryKey])) {
			throw new Exception('CommentsComponent: missing view variable ' . $this->viewVariable . ' or value for primary key ' . $primaryKey . ' of model ' . $this->modelName);
		}

		$id = $this->controller->viewVars[$this->viewVariable][$this->modelName][$primaryKey];
		$options = compact('displayType', 'id');
		$this->_processActions($options);

		try {
			$data = $this->_call('fetchData' . Inflector::camelize($displayType), array($options));
		} catch (NoMethodException $exception) {
			$data = $this->_call('fetchData', array($options));
		}

		$this->controller->set($this->viewComments, $data);
	}
/**
 * Tree representaion. Paginable.
 *
 * @param array $options
 * @return array
 * @access public
 */
	public function callback_fetchDataTree($options) {
		$conditions = $this->_prepareModel($options);
		$order = array('Comment.lft' => 'asc');
		$limit = 10;
		$this->controller->paginate['Comment'] = compact('order', 'conditions', 'limit');
		$data = $this->controller->paginate('Comment');
		$parents = array();
		if (isset($data[0]['Comment'])) {
			$rec = $data[0]['Comment'];
			$conditions[] = array('Comment.lft <' => $rec['lft']);
			$conditions[] = array('Comment.rght >' => $rec['rght']);
			$parents = $this->controller->{$this->modelName}->Comment->find('all', compact('conditions', 'order'));
		}
		return array_merge($parents, $data);
	}
/**
 * Flat representaion. Paginable
 *
 * @param array $options
 * @return array
 * @access public
 */
	public function callback_fetchDataFlat($options) {
		$conditions = $this->_prepareModel($options);
		return $this->controller->paginate($this->assocName, $conditions);
	}
/**
 * Threaded method. Non paginable. Whole data fetched.
 *
 * @param array $options
 * @return array
 * @access public
 */
	public function callback_fetchDataThreaded($options) {
		$Comment =& $this->controller->{$this->modelName}->Comment;
		$conditions = $this->_prepareModel($options);
		$fields = array('Comment.id', 'Comment.user_id', 'Comment.foreign_key', 'Comment.parent_id', 'Comment.approved', 'Comment.title', 'Comment.body', 'Comment.slug', 'Comment.created', $this->modelName . '.id', $this->userModel . '.id', $this->userModel . '.' . $Comment->{$this->userModel}->displayField, $this->userModel . '.slug');
		$order = array('Comment.parent_id' => 'asc', 'Comment.created' => 'asc');
		return $Comment->find('threaded', compact('conditions', 'fields', 'order'));
	}
/**
 * Default method. Flat method called.
 *
 * @param array $options
 * @access protected
 * @return array
 */
	public function callback_fetchData($options) {
		return $this->_fetchDataFlat($options);
	}
/**
 * Prepare model association to fetch data
 *
 * @param array $options
 * @return boolean
 * @access protected
 */
	protected function _prepareModel($options) {
		$params = array(
			'isAdmin' => $this->Auth->user('admin') == true,
			'userModel' => $this->userModel,
			'userData' => $this->Auth->user(),
		);
		return $this->controller->{$this->modelName}->commentBeforeFind(array_merge($params, $options));
	}

/**
 * Prepare passed parameters
 *
 * @access protected
 */
	public function callback_prepareParams() {
		$this->commentParams = array_merge($this->commentParams, array(
			'viewComments' => $this->viewComments,
			'modelName' => $this->modelName,
			'userModel' => $this->userModel));
		$allowedParams = array('comment', 'comment_action');
		foreach ($allowedParams as $param) {
			if (isset($this->controller->passedArgs[$param])) {
				$this->commentParams[$param] = $this->controller->passedArgs[$param];
			}
		}
	}
/**
 * Handle adding comments
 *
 * @param integer $modelId
 * @param integer $commentId Parent comment id
 * @param string $displayType
 * @access public
 */
	public function callback_add($modelId, $commentId, $displayType, $data = array()) {
		if (!empty($this->controller->data)) {
			if (!empty($this->controller->data['Comment']['title'])) {
				$data['Comment']['title'] = $this->Utils->cleanHtml($this->controller->data['Comment']['title']);
			}
			$data['Comment']['body'] = $this->Utils->cleanHtml($this->controller->data['Comment']['body']);
			$modelName = $this->modelName;
			$options = array(
				'userId' => $this->Auth->user('id'),
				'modelId' => $modelId,
				'modelName' => $this->controller->{$this->modelName}->name,
				'defaultTitle' => $this->controller->defaultTitle,
				'data' => $data,
				'permalink' => $this->controller->{$this->modelName}->permalink($modelId));
			$result = $this->controller->{$this->modelName}->commentAdd($commentId, $options);

			if (!is_null($result)) {
				if ($result) {
					try {
						$options['commentId'] = $result;
						$this->_call('afterAdd', array($options));
					} catch (NoMethodException $exception) {
					}
					$this->flash(__d('comments', 'The Comment has been saved.', true));
					$this->redirect(array('#' => 'comment' . $result));
				} else {
					$this->flash(__d('comments', 'The Comment could not be saved. Please, try again.', true));
				}
			}
		}
	}
/**
 * Handle approval of comments
 *
 * @param string $modelId
 * @param string $commentId
 * @access public
 */
	public function callback_toggleApprove($modelId, $commentId) {
		if (!($this->controller->passedArgs['comment_Action'] == 'toggle_approve' && $this->controller->Auth->user('admin') == true)) {
			 throw new BlackHoleException(__d('comments', 'Nonrestricted operation', true));
		}
		if ($this->controller->{$this->modelName}->commentToggleApprove($commentId)) {
			$this->flash(__d('comments', 'The Comment has been deleted.', true));
		} else {
			$this->flash(__d('comments', 'Error appear during comment deleting. Try later.', true));
		}
	}
/**
 * Deletes comments
 *
 * @param string $modelId
 * @param string $commentId
 * @access public
 */
	public function callback_delete($modelId, $commentId) {
		if ($this->controller->{$this->modelName}->commentDelete($commentId)) {
			$this->flash(__d('comments', 'The Comment has been deleted.', true));
		} else {
			$this->flash(__d('comments', 'Error appear during comment deleting. Try later.', true));
		}
		$this->redirect();
	}
/**
 * Flash message.
 * Special behavior for ajax queries
 * @access public
 */
	public function flash($message) {
		$isAjax = $this->controller->params['isAjax'];
		if ($isAjax) {
			$this->controller->set('messageTxt',$message);
		} else {
			$this->Session->setFlash($message);
		}
	}
/**
 * Redirect
 *
 * @param array $urlBase
 * @access public
 */
	public function redirect($urlBase = array()) {
		$isAjax = $this->controller->params['isAjax'];
		$url = array();
		foreach ($this->controller->passedArgs as $key => $value) {
			if (is_numeric($key)) {
				$url[] = $value;
			}
		}
		$url = array_merge($url, $urlBase);
		if ($isAjax) {
			$this->controller->set('redirect', $url);
		} else {
			$this->controller->redirect($url);
		}
		if ($isAjax) {
			$this->controller->set('ajaxMode', true);
		}
	}
/**
 * Call action from commponent or overriden action from controller.
 *
 * @param string $method
 * @param array $args
 * @return mixed
 * @access protected
 */
	protected function _call($method, $args = array()) {
		$methodName = 'callback_comments' .  Inflector::camelize(Inflector::underscore($method));
		$localMethodName = 'callback_' .  $method;
		if (method_exists($this->controller, $methodName)) {
			return call_user_func_array(array(&$this->controller, $methodName), $args);
		} elseif (method_exists($this, $localMethodName)) {
			return call_user_func_array(array(&$this, $localMethodName), $args);
		} else {
			throw new NoMethodException();
		}
	}
/**
 * Generate permalink to page
 *
 * @access public
 */
	public function permalink() {
		$params = array();
		foreach (array('admin', 'controller', 'action', 'plugin') as $name) {
			if (isset($this->controller->params['name'])) {
				$params[$name] = $this->controller->params['name'];
			}
		}
		if (isset($this->controller->params['pass'])) {
			$params = array_merge($params, $this->controller->params['pass']);
		}
		if (isset($this->controller->params['named'])) {
			foreach ($this->controller->params['named'] as $k => $v) {
				if (!in_array($k, $this->_supportNamedParams)) {
					$params[$k] = $v;
				}
			}
		}
		return Router::url($params, true);
	}
}
?>