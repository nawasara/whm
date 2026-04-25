<?php

namespace Nawasara\Whm\Jobs\Email;

class ChangeWhmEmailPasswordJob extends AbstractWhmEmailJob
{
    protected function action(): string
    {
        return 'update_password';
    }

    protected function execute(): array
    {
        $email = $this->payload['email'];
        $password = $this->payload['password'];

        $record = $this->record();
        if (! $record) {
            throw new \RuntimeException("Local record not found: {$email}");
        }

        $whm = $this->client();
        $cpanelUser = $whm->defaultCpanelUser();

        $result = $whm->changeEmailPassword($cpanelUser, $email, $password);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'WHM rejected passwd_pop');
        }

        // Password berhasil diubah — content state tidak berubah (quota/suspend tetap)
        $this->refreshLocalRecord($record);

        return ['email' => $email];
    }
}
