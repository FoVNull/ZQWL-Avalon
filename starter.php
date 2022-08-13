<?php

use Workerman\Worker;
use Workerman\Lib\Timer;

require_once __DIR__ . '/Workerman/Autoloader.php';

$worker = new Worker("websocket://0.0.0.0:1919");

$worker->count = 1;
$worker->uidConnections = array();
$worker->uid_name = array();
$car_owner = 0;
$car = array();
$vote1 = [];
$vote2 = [];

$first = 1;

$roles = [
    //"merlin", "perceval","morgana","assassin","civilian1"
    1 => array("平民1"),
    2 => array("刺客","莫甘娜"),
    5 => array("梅林", "派西维尔","莫甘娜","刺客","平民1"),
    6 => array("梅林", "派西维尔","莫甘娜","刺客","平民1","平民2"),
    7 => array("梅林", "派西维尔","莫甘娜","刺客","莫德雷德","平民1","平民2"),
    8 => array("梅林", "派西维尔","莫甘娜","刺客","莫德雷德","平民1","平民2","平民3"),
    9 => array("梅林", "派西维尔","莫甘娜","刺客","莫德雷德","奥伯伦","平民1","平民2","平民3"),
    10 => array("梅林", "派西维尔","莫甘娜","刺客","莫德雷德","奥伯伦","平民1","平民2","平民3","平民4")
];

$worker->onWorkerStart = function($worker)
{

};

$worker->onClose = function($connection){
    
};
$worker->onMessage = function($connection, $data){
    global $worker;
    global $roles;
    global $car;
    global $vote1;
    global $vote2;
    global $car_owner;
    global $first;
    # 准备房间
    if(substr($data,0,1)=="!")
    {   
        $connection->uid = $first;
        $first += 1;
        $worker->uidConnections[$connection->uid] = $connection;
        $worker->uid_name[$connection->uid] = substr($data,1);
        if($connection->uid == 1){
            $worker->uidConnections[$connection->uid]->send("host");
            Timer::add(1, function()use($worker)
            {
                // 遍历当前进程所有的客户端连接
                foreach(array_values($worker->connections) as $i => $conn)
                {
                    $message = "";
                    foreach(array_values($worker->uid_name) as $key => $value){
                        $message = $message.$key.",".$value.";";
                    };
                    $conn->send($message);
                }
            });
        };
    };
    # 开始游戏
    if($data=="start"){
        Timer::del(1);
        $num = sizeof($worker->uidConnections);
        $role_list = $roles[$num];
        shuffle($role_list);
        $role_dic = [];
        foreach($role_list as $i => $role){
            $role_dic[$role] = $i+1;
        };
        foreach($role_list as $i => $role){
            $worker->uidConnections[$i+1]->send("#".visual($role, $role_dic));
        };

        $m = 0;
        if(intval(substr(date('i'),1,1))==0){
            $m=10;
        }else{
            $m = intval(substr(date('i'),1,1));
        }
        $owner_id = $m%sizeof($worker->uidConnections)+1;
        $car_owner = $owner_id;
        $worker->uidConnections[$owner_id]->send("@owner");
        foreach($worker->uidConnections as $conn){
            $conn->send("@car".$owner_id);
        };
    };
    // 暂定车上成员
    if(substr($data,0,1)=="%"){
        foreach($worker->uidConnections as $conn){
            $conn->send($data);
        };
    };
    // 发车
    if(substr($data,0,2)=="^!"){
        $mem = explode("%", substr($data,2));
        $car = array_slice($mem,0,-1);
        foreach($worker->uidConnections as $conn){
            $conn->send("vote1");
        };
    };
    // 发车投票
    if(substr($data,0,2)=="v1"){
        foreach(array_values($worker->uidConnections) as $i => $conn){
            if($conn == $connection){
                $vote1[$i+1] = substr($data,2);
                break;
            };
        };
        //发车归票
        if(sizeof($vote1)==sizeof($worker->uidConnections)){
            $ag_num = 0;
            $res1 = "^v1";
            for($i=1;$i<=sizeof($vote1);$i+=1){
                if($vote1[$i]=="agree"){
                    $ag_num+=1;
                    $res1 = $res1.$i.",agree;";
                }else{
                    $res1 = $res1.$i.",reject;";
                };
            };
            # 广播发车投票结果
            foreach($worker->uidConnections as $conn){
                $conn->send($res1);
            };
            # 发成则向车上人发票
            if($ag_num > sizeof($vote1)/2){
                foreach($car as $m){
                    $worker->uidConnections[intval($m)+1]->send("vote2");
                };
            }else{
                #没发成直接下一个
                $car_owner = ($car_owner)%sizeof($worker->uidConnections)+1;
                $worker->uidConnections[$car_owner]->send("@owner");
                foreach($worker->uidConnections as $conn){
                    $conn->send("@car".$car_owner);
                    # 广播发车不成功
                    $conn->send("*");
                    $car = array();
                    $vote1 = [];
                    $vote2 = [];
                };
            };
        };
    }
    if($data == "remake"){
        foreach($worker->uidConnections as $conn){
            $conn->send("remake^");
        };
    };
    if($data=="exit"){
        foreach($worker->connections as $conn){
            $conn->send("exit^");
            $conn->close();
        };
        $first = 0;
        Worker::stopAll();
    }
    # 车上人投票
    if(substr($data,0,2)=="v2"){
        array_push($vote2,substr($data,2));
        // foreach(array_values($worker->connections) as $i => $conn){
        //     if($conn == $connection){
        //         array_push($vote2,substr($data,2));
        //     };
        // };
        if(sizeof($vote2)==sizeof($car) && sizeof($vote2)!=0){
            $ag_num = 0;
            $rj_num = 0;
            $res2 = "^v2";
            foreach($vote2 as $v){
                if($v=="agree"){
                    $ag_num++;
                    //$res2 = $res2.$i.",agree;";
                }else{
                    $rj_num++;
                    //$res2 = $res2.$i.",reject;";
                };
            }
            $res2 = $res2.";".$ag_num.";".$rj_num;
            # 广播投票结果
            foreach($worker->uidConnections as $conn){
                $conn->send($res2);
            };
            # 投完下一个
            $car_owner = ($car_owner)%sizeof($worker->uidConnections)+1;
            $worker->uidConnections[$car_owner]->send("@owner");
            foreach($worker->uidConnections as $conn){
                $conn->send("@car".$car_owner);
                $car = array();
                $vote1 = [];
                $vote2 = [];
            };
        };
    };
};

function visual($r, $role_dic){
    $str = "";
    switch ($r){
        case "梅林":
            $str = $r.";"."n,".$role_dic["莫甘娜"]."-n,".$role_dic["刺客"];
            if(isset($role_dic["奥伯伦"])){
                $str .= "-n,".$role_dic["奥伯伦"];
            };
            break;
        case "派西维尔":
            $str = $r.";"."u,".$role_dic["梅林"]."-u,".$role_dic["莫甘娜"];
            break;
        case "莫甘娜":
            $str = $r.";"."n,".$role_dic["刺客"];
            if(isset($role_dic["莫德雷德"])){
                $str .= "-m,".$role_dic["莫德雷德"];
            };
            break;
        case "刺客":
            $str = $r.";"."n,".$role_dic["莫甘娜"];
            if(isset($role_dic["莫德雷德"])){
                $str .= "-m,".$role_dic["莫德雷德"];
            };
            break;
        case "莫德雷德":
            $str .= $r.";"."n,".$role_dic["莫甘娜"]."-n,".$role_dic["刺客"];
            break;
        default:
            $str = "@".$r;
    }
    return $str;
}

Worker::runAll();
?>