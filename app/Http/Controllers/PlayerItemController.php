<?php

namespace App\Http\Controllers;

use App\Models\PlayerItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlayerItemController extends Controller
{
    //
    public function addItem(Request $request, $id)
    {
        $playerItemId = [];
        $data = PlayerItem::get();
        $itemId = $request->input('itemId');
        
        //同じ持ち物のデータがある場合はそのレコードの'count'に追加するアイテムの個数分インクリメント
        foreach ($data as $value) {
            if ($value['playerId'] == $id || $value['itemId'] == $itemId) 
            {
                PlayerItem::find($value['id'])->increment('count',$request->input(('count')));
                $playerItemId = $value['id'];
                break;
            }
        }
        
        //同じ持ち物のデータがない場合新しくレコードを作成する
        if(!$playerItemId)
        {
            $playerItemId = PlayerItem::insertGetId([
                'playerId' => $id,
                'itemId' => $request->input('itemId'),
                'count' => $request->input('count')
                ]);
        }
            
        return new Response(PlayerItem::find($playerItemId,['itemId','count']));
    }
}
