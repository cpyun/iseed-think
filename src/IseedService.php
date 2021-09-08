<?php

namespace cpyun\iseed;

use cpyun\iseed\command\IseedCommand;

class IseedService extends \think\Service
{


    public function boot()
    {
        $this->app->bind('iseed', Iseed::class);

        $this->commands([
            IseedCommand::class
        ]);
    }


}
