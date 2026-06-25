<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $inviteUrl
    ) {
    }

    public function build()
    {
        return $this
            ->subject('Has sido invitado a GSEA CRM')
            ->markdown('emails.invitations.invite');
    }
}