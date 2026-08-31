<?php

namespace App\Commands;

use App\Models\User;
use Vendor\Package\Contracts\BigContract;
use Vendor\Package\Support\Contracts\SmallContract;
use Vendor\Package\Thing;

class MyCommand extends Thing implements BigContract, SmallContract
{
    public User $user;

    public function render(array $params)
    {
        $this->user()->where('url', '
