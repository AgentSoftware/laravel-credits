<?php

namespace AgentSoftware\Credits\Tests\TestModels;

use AgentSoftware\Credits\Traits\HasCredits;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasCredits;

    protected $guarded = [];

    protected $table = 'users';
}
