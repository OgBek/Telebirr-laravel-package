<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Exceptions;

class TelebirrServerException extends TelebirrException
{
    protected string $telebirrCode;
    protected string $telebirrMessage;

    public function __construct(string $message, string $telebirrCode = '', string $telebirrMessage = '', \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->telebirrCode = $telebirrCode;
        $this->telebirrMessage = $telebirrMessage;
    }

    public function getTelebirrCode(): string
    {
        return $this->telebirrCode;
    }

    public function getTelebirrMessage(): string
    {
        return $this->telebirrMessage;
    }
}
