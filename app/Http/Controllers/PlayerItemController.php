<?php

namespace App\Http\Controllers;

use Illuminate\Support\Arr;
use App\Models\PlayerItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlayerItemController extends Controller
{
    //
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
}
