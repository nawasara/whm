<?php

namespace Nawasara\Whm\Jobs\Email;

class DeleteWhmEmailJob extends AbstractWhmEmailJob
{
    protected function action(): string
    {
        return 'delete';
    }

    protected function execute(): array
    {
        $email = $this->payload['email'];

        $record = $this->record();
        if (! $record) {
            // Sudah hilang dari DB — anggap selesai
            return ['email' => $email, 'noop' => true];
        }

        $whm = $this->client();
        $cpanelUser = $whm->defaultCpanelUser();

        $ok = $whm->deleteEmailAccount($cpanelUser, $email);
        if (! $ok) {
            throw new \RuntimeException("WHM rejected delete_pop for {$email}");
        }

        // Hapus dari DB juga
        $record->delete();

        return ['email' => $email, 'deleted' => true];
    }
}
