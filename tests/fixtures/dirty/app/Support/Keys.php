<?php

class Keys
{
    public function aws(): string
    {
        return 'AKIA1234567890ABCDEF';
    }

    public function github(): string
    {
        return 'ghp_FAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE';
    }

    public function slack(): string
    {
        return 'xoxb-FAKE-TOKEN-NOT-REAL';
    }

    public function pem(): string
    {
        return '-----BEGIN OPENSSH PRIVATE KEY-----\nFAKENOTREAL\n-----END OPENSSH PRIVATE KEY-----';
    }
}
