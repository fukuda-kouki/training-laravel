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
        $dataArr =['hp', 'mp'];

        //ステータスが上限に達するまでに使用できるアイテムの個数を数える
        $tempValue = $playerData->value($dataArr[$data->value('item_id') + 1]);
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
            $value = $playerData->value($dataArr[$request->input('itemId') + 1]) + $effectValue * $useCount;
            $playerData->update([$dataArr[$request->input('itemId') + 1] => min($value, PlayerItemController::MAX_STATUS)]);
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
}
