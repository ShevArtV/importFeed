<?php

class myClass
{

    public array $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function methodA()
    {
        $this->methodB();
    }

    public function methodB(){
        $this->methodC();
    }

    public function methodC(){
       // код выбрасывающий исключение
    }
}