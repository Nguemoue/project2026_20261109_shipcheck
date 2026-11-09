<?php

class Mysql
{
    public function byId($link, int $id)
    {
        return mysqli_query($link, "select * from users where id = $id");
    }
}
