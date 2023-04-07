<?php

namespace App\Http\Controllers;

use App\Models\PlayerItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Player;
use App\Models\Item;

class PlayerItemController extends Controller
{

    //プレイヤーのステータス最大値の定数を宣言
    const MAX_STATUS = 200;

    //アイテム追加関数
    public function addItem(Request $request, $id)
    {
        $data = PlayerItem::query()->
        where([['player_id', $id],['item_id', $request->input('itemId')]])->
        get();
        
        //同じ持ち物のデータがない場合新しくレコードを作成する
        if($data->isEmpty())
        {
            PlayerItem::insert([
                'player_id' => $id,
                'item_id' => $request->input('itemId'),
                'count' => $request->input('count')
            ]);
        }
        else //同じ持ち物のデータがある場合はそのレコードの'count'に追加するアイテムの個数分インクリメント
        {
            PlayerItem::query()->
            where([['player_id', $id],['item_id', $request->input('itemId')]])->
            increment('count', $request->input('count'));
        }

        $playerItemData = PlayerItem::query()->
        select('item_id','count')->
        where([['player_id', $id],['item_id', $request->input('itemId')]]);

        return new Response([
            'itemId'=> $playerItemData->value('item_id'),
            'count' => $playerItemData->value('count')
        ]);
    }

    //アイテム使用関数
    public function useItem(Request $request, $id)
    {
        //該当するデータの検索
        $data = PlayerItem::query()->
        where([['player_id', $id],['item_id', $request->input('itemId')]]);

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

        //ステータスが上限に達するまでに使用できるアイテムの個数を数える
        if($data->value('item_id') == 1)
        {
            $tempValue = $playerData->value('hp');
            while($tempValue < PlayerItemController::MAX_STATUS)
            {
                $tempValue += Item::where('id', $request->input('itemId'))->value('value');
                $useablecount++;
            }
        }
        else if ($data->value('item_id') == 2)
        {
            $tempValue = $playerData->value('mp');
            while($tempValue < PlayerItemController::MAX_STATUS)
            {
                $tempValue += Item::where('id', $request->input('itemId'))->value('value');
                $useablecount++;
            }
        }

        //使用可能個数がゼロ(該当するステータスがすでに上限)の場合
        if($useablecount == 0)
        {
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

        //実際に使用する個数の決定
        $useCount = min($count,$useablecount);

        //アイテムを使用する
        if($request->input('itemId') == 1)
        {
            $playerData->update(['hp' => min($playerData->value('hp') + Item::where('id', $request->input('itemId'))->value('value') * $useCount, PlayerItemController::MAX_STATUS)]);
        }
        else if($request->input('itemId') == 2)
        {
            $playerData->update(['mp' => min($playerData->value('mp') + Item::where('id', $request->input('itemId'))->value('value') * $useCount , PlayerItemController::MAX_STATUS)]);
        }
        $data->decrement('count', $useCount);

        //所持数がゼロになったレコードの削除
        PlayerItem::where('count', 0)->delete();
        
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
}
