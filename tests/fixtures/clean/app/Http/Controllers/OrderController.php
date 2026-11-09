<?php

namespace App\Http\Controllers;

class OrderController
{
    public function show(int $id)
    {
        // dd($id);
        /* var_dump($id); */
        $url = 'http://example.test/dd(not-a-call)';
        $label = "do not treat this string as code: dump(\$id)";
        $flag = getenv('APP_DEBUG');

        // shipcheck-ignore
        dd('intentional breakpoint while developing locally');
        dump($id); // shipcheck-ignore

        return DB::select('select * from orders where id = ?', [$id]);
    }
}
