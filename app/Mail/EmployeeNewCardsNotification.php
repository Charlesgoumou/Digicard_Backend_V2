<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployeeNewCardsNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $employeeName;
    public $cardQuantity;
    public $orderNumber;
    public $companyName;
    public $loginUrl;

    public function __construct($employeeName, $cardQuantity, $orderNumber, $companyName, $loginUrl)
    {
        $this->employeeName = $employeeName;
        $this->cardQuantity = $cardQuantity;
        $this->orderNumber = $orderNumber;
        $this->companyName = $companyName;
        $this->loginUrl = $loginUrl;
    }

    public function build()
    {
        return $this->subject('Nouvelles cartes DigiCard à configurer')
                    ->view('emails.employee-new-cards');
    }
}
