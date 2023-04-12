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
        $data = PlayerItem::lockForUpdate()->
        where([['player_id', $id],['item_id', $request->input('itemId')]]);
        
        //同じ持ち物のデータがない場合新しくレコードを作成する
        if($data->doesntExist())
        {
            try{
                PlayerItem::insert([
                    'player_id' => $id,
                    'item_id' => $request->input('itemId'),
                    'count' => $request->input('count') ]);
                $data->lockForUpdate();
            } catch(Exception $e) {
                DB::rollBack();
                return new Response("所持テータレコードの作成に失敗");
            }
        }
        else //同じ持ち物のデータがある場合はそのレコードの'count'に追加するアイテムの個数分インクリメント
        {
            try{
                PlayerItem::query()->
                where([['player_id', $id],['item_id', $request->input('itemId')]])->
                increment('count', $request->input('count'));
            } catch(Exception $e) {
                DB::rollBack();
                return new Response("所持数の加算に失敗");
            }
           
        }

        $returnResult = [
            'itemId'=> $data->value('item_id'),
            'count' => $data->value('count')
        ];

        //トランザクション終了
        DB::commit();

        return new Response($returnResult);
    }

    //アイテム使用関数
    public function useItem(Request $request, $id)
    {
        //該当するデータの検索
        $data = PlayerItem::query()->
        where([['player_id', $id],['item_id', $request->input('itemId')]]);

        $count = 0;
        if($request->has('count'))
        {
            $count = $request->input('count');
        }
        else //リクエストに'count'が含まれていなければ使用数を1にする
        {
            $count = 1;
        }
        
        //所持数が使用数以下もしくはゼロ（該当するレコードがない）の場合エラー
        if($data->get()->isEmpty() || $data->value('count') < $count)
        {
            return new Response("アイテムを{$count}個所持していません。",Response::HTTP_BAD_REQUEST);
        }

        $playerData = Player::where('id',$id);
        $useablecount = 0;
        $dataArr =['hp', 'mp'];

        //ステータスが上限に達するまでに使用できるアイテムの個数を数える
        $tempValue = $playerData->value($dataArr[$data->value('item_id') - 1]);
        $effectValue = Item::where('id', $request->input('itemId'))->value('value');
        while($tempValue < PlayerItemController::MAX_STATUS)
        {
            $tempValue += $effectValue;
            $useablecount++;
        }

        //使用可能個数がゼロ以上の場合に使用
        if($useablecount > 0)
        {
            //実際に使用する個数の決定
            $useCount = min($count,$useablecount);

            //アイテムを使用する
            $value = $playerData->value($dataArr[$request->input('itemId') - 1]) + $effectValue * $useCount;
            $playerData->update([$dataArr[$request->input('itemId') - 1] => min($value, PlayerItemController::MAX_STATUS)]);
            $data->decrement('count', $useCount);
        }

        return new Response([
            'itemId' => $request->input('itemId'),
            'count' => $data->value('count'),
            'player' => [
                'id' => $id,
                'hp' => $playerData->value('hp'),
                'mp' => $playerData->value('mp')
            ]
        ]);
    }

    

    //ガチャ関数
    public function useGacha(Request $request, $id)
    {
        //トランザクションの開始
        DB::beginTransaction();
        //ガチャを引くプレイヤーのデータを取得&ロック
        $playerData = Player::lockForUpdate()->where('id',$id);
        if($playerData->doesntExist()) 
        {
            DB::rollback();
            return new response("id:{$id}のプレイヤーデータが存在しない",Response::HTTP_BAD_REQUEST);
        }

        //お金が足りなかったら
        if($playerData->value('money') < self::GACHA_PRICE * $request->input('count'))
        {
            DB::rollback();
            return new Response('お金が不足しています。',Response::HTTP_BAD_REQUEST);
        }

        //お金を引く
        try {
            $playerData->decrement('money',self::GACHA_PRICE * $request->input('count'));
        } catch (Exception $e) {
            DB::rollback();
            return new Response('所持金の減算に失敗');
        }
        $money = $playerData->value('money'); //引いた後の所持金

        //ガチャの結果を生成
        $itemData = Item::select('percent')->get();
        if($itemData->isEmpty()) 
        {
            DB::rollback();
            return new response("アイテムデータの取得に失敗",Response::HTTP_BAD_REQUEST);
        }

        $result = [];
        for($i = 0; $i < $request->input('count'); $i++)
        {
            $itemId = 0; //ガチャから出たアイテムのId
           
            //ガチャの結果を抽選
            $random = mt_rand(1,100);
            $percent = 0;
            while($random > $percent && $itemId <= $itemData->count())
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

        //結果のアイテムを取得&ロック
        $insertData = [];
        $data = PlayerItem::lockForUpdate()->
            where('player_id', $id)->get();
        
        foreach($result as $key => $value)
        {
            $updated = false;
            foreach($data as $tempData)
            {
                if($tempData['item_id'] == $key)
                {
                    try{
                        PlayerItem::query()->
                        where([['player_id', $id],['item_id', $key]])->
                        increment('count', $value);
                    } catch(Exception $e){
                        DB::rollback();
                        return new Response('所持アイテムの加算に失敗');
                    }
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
        if(count($insertData) > 0)
        {
            
            try{
                PlayerItem::insert($insertData);
            } catch(Exception $e){
                DB::rollback();
                return new Response('所持アイテムレコードの追加に失敗');
            }
        }

       //レスポンスの取得
        $returnResult = [];
        foreach($result as $key => $value)
        {
            $returnResult[] = [
                'itemId' => $key,
                'count' => $value
            ];
        }
        $items = [ 'Items' => PlayerItem::where('player_id', $id)->select('item_id','count')->get()];

         //トランザクション終了
         DB::commit();
        
        return new Response([
            'result' => $returnResult,
            'player' => [
                $money,
                $items
            ]
        ]);
    }
}
