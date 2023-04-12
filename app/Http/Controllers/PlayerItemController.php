<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Models\PlayerItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Player;
use App\Models\Item;

class PlayerItemController extends Controller
{

    //定数宣言
    const MAX_STATUS = 200; //プレイヤーのステータス最大値の定数
    const GACHA_PRICE = 10; //ガチャの値段定数

    //アイテム追加関数
    public function addItem(Request $request, $id)
    { 
        //トランザクション開始
        DB::beginTransaction();
        try 
        {
            //所持品データの取得&ロック
            $data = PlayerItem::lockForUpdate()->where([['player_id', $id],['item_id', $request->input('itemId')]])->get();
            
            //同じ持ち物のデータがない場合新しくレコードを作成する
            if($data->isEmpty())
            {
            
                PlayerItem::insert([
                    'player_id' => $id,
                    'item_id' => $request->input('itemId'),
                    'count' => $request->input('count') ]);

                    $returnResult = [
                        'itemId'=> $request->input('itemId'),
                        'count' => $request->input('count')];
            }
            else //同じ持ち物のデータがある場合はそのレコードの'count'に追加するアイテムの個数分インクリメント
            {
                PlayerItem::query()->
                where([['player_id', $id],['item_id', $request->input('itemId')]])
                ->increment('count', $request->input('count'));
        
                $returnResult = [
                    'itemId'=> $request->input('itemId'),
                    'count' => $data[0]['count'] + $request->input('count')];
            }
            DB::commit();
        } 
        catch(Exception $e)
        {
            DB::rollBack();
            return new Response($e);
        }

        return new Response($returnResult);
    }

    //アイテム使用関数
    public function useItem(Request $request, $id)
    {
        $count = 1; //使用個数

        //リクエストに'count'が含まれていれば使用数を'count'にする
        if($request->has('count')) $count = $request->input('count');

        //トランザクション開始
        DB::beginTransaction();
        try 
        {
            //アイテムを使用するプレイヤーデータの取得&ロック
            $playerData = Player::lockForUpdate()->where('id',$id)->get();
            if($playerData->isEmpty()) throw new Exception('該当するプレイヤーデータなし');
        
            //該当するデータの検索&ロック
            $data = PlayerItem::lockForUpdate()->
                where([['player_id', $id],['item_id', $request->input('itemId')]])->get();
            if($data->isEmpty()) throw new Exception('該当する所持データなし');
            if($data[0]['count'] < $count) throw new Exception("アイテムを{$count}個所持していない");
            
            $useablecount = 0; //使用可能個数
            $statusArray = [1 =>"hp",2 => "mp"]; //itemIdに基づいた参照ステータスの配列

            //ステータスが上限に達するまでに使用できるアイテムの個数を数える
            //計算に必要な値を取得
            $statusValue = $playerData[0][$statusArray[$request->input('itemId')]];
            $effectValue = Item::where('id', $request->input('itemId'))->value('value');
            
            //数える
            while($statusValue < self::MAX_STATUS)
            {
                $statusValue += $effectValue;
                $useablecount++;
            }

            //実際に使用する個数、回復量の決定
            $useCount = min($count,$useablecount);
            $value = $playerData[0][$statusArray[$request->input('itemId')]] + $effectValue * $useCount;

            //使用個数がゼロ以上の場合に使用
            if($useCount > 0)
            {
               //アイテムを使用する
                Player::where('id',$id)->update([$statusArray[$request->input('itemId')] => min($value, self::MAX_STATUS)]);
                PlayerItem::where([['player_id', $id],['item_id', $request->input('itemId')]])->decrement('count', $useCount);
            }

            $returnResult = [
                'itemId' => $request->input('itemId'),
                'count' => $data[0]['count'] - $useCount,
                'player' => [
                    'id' => $id,
                    'hp' => $playerData[0][$statusArray[1]],
                    'mp' => $playerData[0][$statusArray[2]]]];

            DB::commit();
        } 
        catch(Exception $e)
        {
            DB::rollBack();
            return new Response($e,Response::HTTP_BAD_REQUEST);
        }
        
        return new Response($returnResult);
    }

    

    //ガチャ関数
    public function useGacha(Request $request, $id)
    {
        //トランザクションの開始
        DB::beginTransaction();
        try
        {
            //ガチャを引くプレイヤーのデータを取得&ロック
            $playerData = Player::lockForUpdate()->where('id',$id)->get();
            if($playerData->isEmpty()) throw new Exception('該当するプレイヤーデータなし');
            
            //お金が足りなかったら
            if($playerData[0]['money'] < self::GACHA_PRICE * $request->input('count')) throw new Exception('所持金が不足');

            //ガチャの代金を引く
            Player::where('id',$id)->decrement('money',self::GACHA_PRICE * $request->input('count'));
            $money = $playerData[0]['money'] - self::GACHA_PRICE * $request->input('count'); //引いた後の所持金

            //ガチャの結果を生成
            $itemData = Item::select('percent')->get();
            if($itemData->isEmpty()) throw new Exception('該当するアイテムデータなし');

            $result = [];
            for($i = 0; $i < $request->input('count'); $i++)
            {
                $itemId = 0; //ガチャから出たアイテムのId
            
                //ガチャの結果を抽選
                $random = mt_rand(1,100);
                $percent = 0;
                while($random > $percent && $itemId <= $itemData[0]->count())
                {
                    $itemId++;
                    $percent += $itemData[$itemId - 1]['percent'];
                }

                //結果を配列で格納
                if(array_key_exists($itemId,$result))
                {
                    $result[$itemId] += 1;
                }
                else
                {
                    $result += array($itemId => 1);
                }
            }
            //結果のアイテムを取得
            $data = PlayerItem::where('player_id', $id)->get();
            $insertData = [];
            foreach($result as $key => $value)
            {
                $updated = false;
                foreach($data as $tempData)
                {
                    if($tempData['item_id'] == $key)
                    {
                        PlayerItem::query()->
                            where([['player_id', $id],['item_id', $key]])->
                            increment('count', $value);
                        $updated = true;
                        break;
                    }
                }

                if(!$updated)
                {
                    array_push($insertData, [
                        'player_id' => $id,
                        'item_id' => $key,
                        'count' => $value
                        ]);
                }
            }
            
            //作成したinsertDataをインサートする
            if(count($insertData) > 0) PlayerItem::insert($insertData);
            
            //レスポンスの取得
            $returnResult = [];
            $items = [ 'Items' => PlayerItem::where('player_id', $id)->select('item_id','count')->get()];
            foreach($result as $key => $value)
            {
                $returnResult[] = [
                    'itemId' => $key,
                    'count' => $value
                ];
            }
            
            //トランザクション終了
            DB::commit();
        }
        catch(Exception $e)
        {
            DB::rollBack();
            return new Response($e,Response::HTTP_BAD_REQUEST);
        }
        
        return new Response([
            'result' => $returnResult,
            'player' => [
                $money,
                $items
            ]
        ]);
    }
}
