<?php

namespace App\Http\Controllers;

class OrderController
{
    public function show(int $id)
    {
        // dd($id);
        /* dump($id); */
        $order = ['id' => $id];
        dd($order);

        return $order;
    }
}
