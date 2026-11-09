<?php

class UserQuery
{
    public function byEmail(string $email)
    {
        return DB::select("select * from users where email = '$email'");
    }

    public function byId(int $id)
    {
        return DB::select('select * from users where id = ' . $id);
    }

    public function safe(int $id)
    {
        return DB::select('select * from users where id = ?', [$id]);
    }
}
