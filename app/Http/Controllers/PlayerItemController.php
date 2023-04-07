<?php

namespace App\Http\Controllers;

use App\Models\PlayerItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Player;
use App\Models\Item;

class PlayerItemController extends Controller
{
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

        //所持数がゼロ（該当するレコードがない）の場合エラー
        if($data->get()->isEmpty() || $data->value('count') == 0)
        {
            return new Response('アイテムを所持していません。',Response::HTTP_BAD_REQUEST);
        }

        $playerData = Player::where('id',$id);

        //該当するステータスがすでに上限の場合
        if($playerData->value('hp') >= 200 && $data->value('item_id') == 1 ||
           $playerData->value('mp') >= 200 && $data->value('item_id') == 2 )
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

        //アイテムを使用する
        if($request->input('itemId') == 1)
        {
            $playerData->update(['hp' => min($playerData->value('hp') + Item::find(1)->value('value'), 200)]);
        }
        else if($request->input('itemId') == 2)
        {
            $playerData->update(['mp' => min($playerData->value('mp') + Item::find(2)->value('value'), 200)]);
        }

        $data->decrement('count');
        
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
