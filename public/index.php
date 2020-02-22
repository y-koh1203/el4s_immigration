<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
header("Access-Control-Allow-Origin: *");

$app->get('/', function(Request $req, Response $res, array $args){
   phpinfo();
});

// データの保存処理
$app->get('/store/{userData}', function(Request $req, Response $res, array $args){
    $link = new SQLite3(__DIR__.'/./game.db');
    $userData = explode('-', $args['userData']);

    $sqlFindUser = "select count(*) nou from players where user_name = '${userData[0]}'";
    $r = $link->query($sqlFindUser);

    $resultset = $r->fetchArray(SQLITE3_ASSOC);

    if($resultset['nou'] === 0){
        $payload = json_encode(['result' => 'failed']);
        $res->getBody()->write($payload);
        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    $sqlFindMatch = "select * from matching where host = '${userData[0]}' or enemy = '${userData[0]}'";
    //$sqlFindMatch = "select * from matching where host = 'kai' or enemy = 'ac'";
    $r2 = $link->query($sqlFindMatch);
    $rs2 = $r2->fetchArray(SQLITE3_ASSOC);

    if(!$rs2){
        $payload = json_encode(['result' => 'failed']);
        $res->getBody()->write($payload);
        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    $sqlInsertUserData = "insert or replace into user_data ('host_name', 'enemy_name', 'user_data') values ('${rs2['host']}', '${rs2['enemy']}', '${userData[1]}')";
    $link->query($sqlInsertUserData);

    $payload = json_encode(['result' => 'success']);
    $res->getBody()->write($payload);
    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

// データの取得処理
$app->get('/get/{userId}', function(Request $req, Response $res, array $args){
    $link = new SQLite3(__DIR__.'/./game.db');
    $userId = $args['userId'];

    $sqlFindUserData = "select * from user_data where host_name = '${userId}' or enemy_name = '${userId}'";
    $r = $link->query($sqlFindUserData);

    $data = $r->fetchArray(SQLITE3_ASSOC);
    $payload = json_encode(['user_data' => $data['user_data']]);
    $res->getBody()->write($payload);
    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

// セットアップ
$app->get('/boot', function(Request $req, Response $res, array $args){
    $link = new SQLite3(__DIR__.'/./game.db');
    $data = [
        'status' => 'ok'
    ];

    $link->query('CREATE TABLE "matching" ( "host" TEXT NOT NULL UNIQUE, "enemy" TEXT )');
    $link->query('CREATE TABLE "players" ( "user_name" TEXT UNIQUE, "role" TEXT, PRIMARY KEY("user_name") )');
    $link->query('CREATE TABLE "user_data" ( "user_name" TEXT NOT NULL UNIQUE, "user_data" TEXT, PRIMARY KEY("user_name") )');
    
    $payload = json_encode($data);
    $res->getBody()->write($payload);
    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

// マッチング状態
$app->get('/status/{userId}', function(Request $req, Response $res, array $args){
    $userId = $args['userId'];
    $link = new SQLite3(__DIR__.'/./game.db');

    // 登録済みのユーザーを検索
    // 未参加であれば、空いているマッチにjoinする
    $sqlSearch = "select * from matching inner join players on matching.host = players.user_name where host = \"${userId}\"";
    $sqlSearch2 = "select * from matching inner join players on matching.enemy = players.user_name where enemy = \"${userId}\"";

    $r1 = $link->query($sqlSearch);
    $r2 = $link->query($sqlSearch2);

    $resultsetHost = [];
    $oppnentsHost = [];
    $rsRolesHost = [];
    $nrow = 0;
    while($row = $r1->fetchArray(SQLITE3_ASSOC)){
        $resultsetHost[] = $row['host'];
        $oppnentsHost[] = $row['enemy'];
        $rsRolesHost = $row['role'];
        $nrow++;
    }

    $resultsetEnemy = [];
    $oppnentsEnemy = [];
    $rsRolesEnemy = [];
    $nrow = 0;
    while($row = $r2->fetchArray(SQLITE3_ASSOC)){
        $resultsetEnemy[] = $row['enemy'];
        $oppnentsEnemy[] = $row['host'];
        $rsRolesEnemy = $row['role'];
        $nrow++;
    }

    // 存在しないユーザー
    if(count($resultsetHost) === 0 && count($resultsetEnemy) === 0){
        $res->getBody()->write(json_encode(['status' => 0, 'enemy' => '', 'role' => '']));
        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    if(count($resultsetHost) > 0 && $oppnentsHost[0] !== null){
        $res->getBody()->write(json_encode(['status' => 1, 'enemy' => $oppnentsHost[0], 'role' => $rsRolesHost[0]]));
        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }
    
    if(count($resultsetEnemy) > 0 && $oppnentsEnemy[0] !== null){
        $res->getBody()->write(json_encode(['status' => 1, 'enemy' => $oppnentsEnemy[0], 'role' => $rsRolesEnemy[0]]));
        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    $res->getBody()->write(json_encode(['status' => 0, 'enemy' => '', 'role' => '']));
    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


// 参加処理
$app->get('/join/{userId}', function(Request $req, Response $res, array $args){
    $userId = $args['userId'];
    $link = new SQLite3(__DIR__.'/./game.db');

    // 登録済みのユーザーを検索
    // 未参加であれば、空いているマッチにjoinする
    $sqlSearch = "select count(*) as nop from players where user_name = \"${userId}\"";
    $result = $link->query($sqlSearch);
    $user = $result->fetchArray(SQLITE3_ASSOC);

    // 登録ずみであればjoin
    if($user['nop'] > 0){
        $r = match($userId, $link);
        $t = $r;

        // マッチングしていない場合
        if($r === $userId){
            $r = 'joined';
        }

        $payload = json_encode(['enemy' => $r]);
        $res->getBody()->write($payload);
        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    // 現在登録されているroleを確認
    $sqlRoleSearch = "select count(role) as roles from players";
    $numberOfRoles = $link->query($sqlRoleSearch);
    $num = $numberOfRoles->fetchArray(SQLITE3_ASSOC);
    $role = 1;

    // role1の人数が奇数ならrole2を登録
    if($num['roles'] %2 !== 0){
        $role = 2;
    }

    //　登録処理
    $sqlInsert = "insert or ignore into players ('user_name', 'role') values ('${userId}', '${role}')";
    $insertResult = $link->query($sqlInsert);

    // マッチング
    $r = match($userId, $link);
    if($r === $userId){
        $r = '';
    }
    
    $payload = json_encode(["enemy" => $r]);
    $res->getBody()->write($payload);
    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

// マッチング処理
function match(string $userId, SQLite3 $link): String {
    // マッチングテーブルを参照する
    $sqlMatchSearch = "select count(*) as noh from matching where host = \"${userId}\" or enemy =  \"${userId}\"";
    $foundMatch = $link->query($sqlMatchSearch);
    $r1 = $foundMatch->fetchArray(SQLITE3_ASSOC);

    // 自分が既に参加していれば自身のidを返す
    if($r1['noh'] > 0){
        return $userId;
    }

    // ホストのみ参加している部屋を検索
    $sqlFindNullMatch = "select host from matching where enemy is null";
    $foundMatchOfNull = $link->query($sqlFindNullMatch);

    $hosts = [];
    $nrow = 0;
    while($res = $foundMatchOfNull->fetchArray(SQLITE3_ASSOC)){
        $hosts[] = $res['host'];
        $nrow++;
    }

    // ホストのみの部屋のマッチングテーブルを更新
    if(count($hosts) > 0){
        $sqlUpdate = "update matching set enemy = '${userId}' where host = '${hosts[0]}'";
        $link->query($sqlUpdate);
        return $hosts[0];
    }

    // ホストのみの部屋がなければ部屋を建てる
    $sqlInsert = "insert or ignore into matching ('host', 'enemy') values ('${userId}', NULL)";
    $link->query($sqlInsert);

    return $userId;
}

$app->get('/finish/{userId}', function(Request $req, Response $res, array $args){
    $userId = $args['userId'];
    $link = new SQLite3(__DIR__.'/./game.db');


    //　削除
    $sqlFindDelete = "select * from matching where host = \"${user_id}\" or enemy = \"${user_id}\"";

    // マッチングと登録ユーザーを物理的に消す
    $sqlDelete = "delete from players where user_name = \"${user_id}\"";
    $sqlDeleteMatching = "delete from matching where host = \"${user_id}\" or enemy = \"${user_id}\"";

    $result = $link->query($sqlSearch);
    $resultMatching = $link->query($sqlDeleteMatching);

    $data = ['result' => 'success'];
    
    // レスポンス返す
    $payload = json_encode($data);
    $res->getBody()->write($payload);
    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->run();