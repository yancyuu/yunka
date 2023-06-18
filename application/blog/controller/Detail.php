<?php

namespace app\blog\controller;

use app\common\controller\Frontend;
use think\Db;

class Detail extends Frontend {

    protected $layout = '';

    protected $noNeedLogin = '*';

    public function _initialize() {
        parent::_initialize(); // TODO: Change the autogenerated stub

        if(!$this->request->isPjax()){
//            $this->pjaxLayout();
//            $this->view->engine->layout('default/layout/layout_' . $this->layout);
        }

    }

	public function index(){
        $id = $this->request->param('id');
        $blog = Db::name('blog')->where(['id' => $id])->find();

        $this->assign([
            'blog' => $blog
        ]);
		return view();
	}






}
