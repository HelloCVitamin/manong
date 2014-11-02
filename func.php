<?php
/**
 * whether it is a GET request
 */
function is_get(){
	return $_SERVER['REQUEST_METHOD'] == 'GET';
}

/**
 * which action is requested
 */
function action(){
	// if(is_get())
	// return 'index';
	return isset($_GET['a']) ? $_GET['a']:'index';
}

/**
 * show the index page
 */
function index(){
	require 'manong.php';
	exit;
}

/**
 * singleton db class
 */
function get_db(){
	static $db=null;
	if(is_null($db)){
		$db=new manongdb();
	}
	return $db;
}

/**
 * log text
 */
$log=true;
function mylog($str){
	global $log;
	if($log){
		if(is_array($str)){
			echo "<pre>";
			print_r($str);
			echo "</pre>";
		}else{
			echo $str.'<br>';
		}
	}
}

/**
 * return json string
 */
function ajax_return($arr){
	header('Content-type:application/json;charset=utf-8');
	echo json_encode($arr);
	exit;
}

/**
 * crawl the content
 */
function crawl(){
	$number=$_POST['number'];

	$mdb=get_db();
	$data=$mdb->get_issue($number);
	$html='';
	if(empty($data)){
		$html .= 'no data';
	}else{
		// echo "<pre>";
		// print_r($data);
		// echo "</pre>";
		foreach ($data as $val) {
			$html .= '<div class="item">';
			$html .= '<hr>';
			$html .= '<form>';
			$html .= "标题：<input type='text' name='title' value='{$val['title']}'><br>";
			$html .= "描述：<input type='text' name='desc' value='{$val['desc']}'><br>";
			$html .= "地址：<input type='text' name='href' value='{$val['href']}'><br>";
			$html .= "<input type='hidden' name='id' value='{$val['id']}'>";
			$html .= "<input type='hidden' name='number' value='{$val['number']}'>";
			$html .= "<input type='text' class='newcate' name='newcate''>";
			$html .= "<button class='add'>添加</button>";
			$html .= "<button class='del''>删除</button>";
			$html .= "</form>";
			$html .= "</div>";
		}
	}
	echo $html;
}

/**
 * load categories
 */
function cate(){
	$mdb=get_db();
	$cate=$mdb->get_cate();
	$html='';
	$html.='<select name="category">';
	$html.='<option value="0">请选择</option>';
	$catearr=[];
	foreach ($cate as $val) {
		$html.='<option value="'.$val.'">'.$val.'</option>';
		$catearr[]=$val;
	}
	$html.='</select>';
	ajax_return(['html'=>$html,'cate'=>$catearr]);
}

/**
 * add an item to db
 */
function add(){
	$cate=trim($_GET['newcate']);
	$cate=$cate ? $cate:$_GET['category'];
	$cate || ajax_return(['res'=>0,'msg'=>'fill category']);
	$data=[
		'title'=>trim($_GET['title']),
		'desc'=>trim($_GET['desc']),
		'href'=>trim($_GET['href']),
		'number'=>trim($_GET['number']),
		'category'=>strtoupper($cate),
		'hash'=>md5(trim($_GET['href'])),
		'addtime'=>time(),
		'ctime'=>time(),
	];
	if(get_db()->add($data)){
		ajax_return(['res'=>1,'msg'=>'success','cate'=>$data['category']]);
	}else{
		ajax_return(['res'=>0,'msg'=>'db error']);
	}
}

/**
 * delete an item from cache
 */
function del(){
	$id=$_GET['id'];
	$rs=get_db()->del_cache($id);
	$res=$rs ? 1:0;
	ajax_return(compact('res'));
}

/**
 * render data to markdown text
 */
function render(){
	$data=get_db()->get_all();
	$current=$data[count($data)-1]['number'];
	$content="码农周刊分类整理
======
码农周刊的类别分的比较大，不易于后期查阅，所以我把每期的内容按语言或技术进行了分类整理。  
码农周刊官方网址 [http://weekly.manong.io/](http://weekly.manong.io/)  
现在已整理到第{$current}期。
";

	$current_cate='';
	foreach ($data as $val) {
		if($val['category'] != $current_cate){
			$content.="\n";
			$content.="##{$val['category']}\n";
			$current_cate=$val['category'];
		}
		$content.="[{$val['title']}]({$val['href']})\n";
	}
	$rs=file_put_contents('./readme.md', $content);
	if($rs){
		ajax_return(['res'=>1]);
	}else{
		ajax_return(['res'=>0,'msg'=>'输出失败']);
	}
}

class manongdb{
	private $pdo;
	private $issue_url='http://weekly.manong.io/issues/';

	function __construct($user='root',$password='',$dbname='manong'){
		$this->pdo=new PDO('mysql:host=localhost;dbname='.$dbname,$user,$password);
		$this->pdo->query('set names utf8');
	}

	/**
	 * get issue data
	 */
	function get_issue($number){
		mylog('searching for cache');
		$data=$this->cache($number);
		if(empty($data)){
			mylog('no cache,start crawling');
			$data=$this->crawl($number);
			if(false === $data){
				die('抓取失败');
			}
		}
		return $data;
	}

	/**
	 * get issue cache
	 */
	function cache($number){
		$data=$this->pdo->query("select * from cache where number={$number}")->fetchAll(PDO::FETCH_ASSOC);
		if($data){
			mylog('cache founded');
		}
		return $data;
	}

	/**
	 * delete an item from cache
	 */
	function del_cache($id){
		return $this->pdo->exec("delete from cache where id={$id}");
	}

	/**
	 * crawl the data
	 */
	function crawl($number){
		$url=$this->issue_url.$number;
		$content=file_get_contents($url);
		$content=str_replace(["\r","\n"], '', $content);
		$pattern='/<h4><a target="_blank" href="(.*?)">(.*?)<\/a>&nbsp;&nbsp;<\/h4>.*?<p>(.*?)<\/p>/';
		$rs=preg_match_all($pattern, $content, $matches);
		if($rs){
			mylog('crawl finished.start parsing');
			$data=array();
			foreach ($matches[1] as $key => $val) {
				if(false !== strpos($matches[3][$key], 'job')){
					continue;
				}
				$item=[
					'title'=>$matches[2][$key],
					'desc'=>$matches[3][$key],
					'href'=>$val,
					'number'=>$number,
					'hash'=>md5($val)
				];
				//加入数据库
				$id=$this->add_cache($item);
				$item['id']=$id;
				mylog('item added to cache');
				// 加入数组
				$data[]=$item;
			}
			return $data;
		}
		mylog('failed to crawl');
		return false;
	}

	/**
	 * add item to cache
	 */
	function add_cache($item){
		$sql=$this->arr2sql('cache',$item);
		$this->pdo->exec($sql);
		$id=$this->pdo->lastInsertId();
		return $id;
	}

	/**
	 * translate array to sql
	 */
	function arr2sql($dbname,$arr,$updateid=null){
		
		if($updateid){
			// return "update {$dbname} set "
			unset($arr['ctime']);
			unset($arr['addtime']);
			$sql="update {$dbname} set ";
			foreach ($arr as $key => $val) {
				$sql.="`{$key}`='{$val}',";
			}
			$sql.='ctime='.time();
			$sql.=" where id={$updateid}";
			return $sql;
		}else{
			$fields=[];
			$values=[];
			foreach ($arr as $key => $v) {
				$fields[]="`{$key}`";
				$values[]="'{$v}'";
			}
			return "insert into {$dbname} (".implode(',', $fields).") values(".implode(',', $values).")";
		}
	}

	/**
	 * get categories
	 */
	function get_cate(){
		$arr=[];
		$rs=$this->pdo->query("select distinct category from issue order by category")->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rs as $val) {
			$arr[]=$val['category'];
		}
		return $arr;
	}

	/**
	 * add an item to db or update it if exists
	 */
	function add($data){
		$rs=$this->pdo->exec($this->arr2sql('issue',$data));
		if($rs){
			return $this->pdo->lastInsertId();
		}
		$rs=$this->pdo->query("select id from issue where hash='{$data['hash']}'")->fetch(PDO::FETCH_ASSOC);
		if(isset($rs['id'])){
			$update=$this->pdo->exec($this->arr2sql('issue',$data,$rs['id']));
			if($update)	return $rs['id'];
		}
		return false;
	}

	/**
	 * fetch all recorded data
	 */
	function get_all(){
		return $this->pdo->query('select * from issue order by category,addtime')->fetchAll(PDO::FETCH_ASSOC);
	}
}
?>