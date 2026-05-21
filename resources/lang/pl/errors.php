<?php

return [

    'admin' => [
        'verification_failed' => 'Weryfikacja podpisu PayNow nie powiodła się. Sprawdź konfigurację klucza PAYNOW_SIGNATURE_KEY.',
        'unauthorized' => 'Autoryzacja API PayNow nie powiodła się. Sprawdź konfigurację klucza PAYNOW_API_KEY.',

        'system_temporarily_unavailable' => 'PayNow jest chwilowo niedostępny. Spróbuj ponownie później.',

        'payment_amount_too_small' => 'Kwota płatności jest poniżej minimum (1,00 PLN).',
        'payment_amount_too_large' => 'Kwota płatności przekracza dozwolone maksimum.',

        'payment_method_not_available' => 'Wybrana metoda płatności jest niedostępna.',

        'authorization_code_expired' => 'Kod BLIK wygasł.',
        'authorization_code_invalid' => 'Nieprawidłowy kod BLIK.',
        'authorization_code_used' => 'Ten kod BLIK został już wykorzystany.',

        'refund_possibility_expired' => 'Zwrot niemożliwy — transakcja starsza niż 6 miesięcy.',
        'insufficient_balance_funds' => 'Niewystarczające saldo sprzedawcy do realizacji zwrotu. Włącz funkcję oczekujących zwrotów w panelu PayNow.',
        'insufficient_card_balance_funds' => 'Niewystarczające saldo karty do realizacji zwrotu. Włącz funkcję oczekujących zwrotów w panelu PayNow.',
        'refund_amount_too_small' => 'Kwota zwrotu jest poniżej dozwolonego minimum.',
        'refund_amount_too_large' => 'Kwota zwrotu przekracza dostępne saldo do zwrotu.',

        'refund_record_not_found' => 'Nie znaleziono rekordu płatności PayNow dla tej transakcji.',
        'refund_not_confirmed' => 'Zwroty można wystawiać tylko dla potwierdzonych płatności (aktualny status: :status).',
        'refund_exceeds_balance' => 'Kwota zwrotu (:amount) przekracza dostępne saldo (:available).',
        'refund_not_cancellable' => 'Zwrot :refund_id nie może zostać anulowany (status: :status). Anulować można tylko zwroty w statusie NEW.',
        'missing_buyer_email' => 'Nie można utworzyć płatności PayNow: brak adresu e-mail w adresie rozliczeniowym lub koncie klienta.',

        'not_found' => 'Zasób PayNow nie został znaleziony.',
        'validation_error' => 'Błąd walidacji żądania PayNow: :message',
        'generic' => 'Błąd API PayNow: :message',
    ],

    'customer' => [
        'system_temporarily_unavailable' => 'Usługa płatności jest chwilowo niedostępna. Spróbuj ponownie za kilka minut.',
        'payment_amount_too_small' => 'Kwota płatności jest zbyt mała.',
        'payment_amount_too_large' => 'Kwota płatności jest zbyt duża.',
        'payment_method_not_available' => 'Wybrana metoda płatności jest niedostępna. Wybierz inną metodę.',
        'authorization_code_expired' => 'Kod BLIK wygasł. Wygeneruj nowy kod i spróbuj ponownie.',
        'authorization_code_invalid' => 'Nieprawidłowy kod BLIK. Sprawdź kod i spróbuj ponownie.',
        'authorization_code_used' => 'Ten kod BLIK został już użyty. Wygeneruj nowy kod.',
        'generic' => 'Płatność nie powiodła się. Spróbuj ponownie lub wybierz inną metodę płatności.',
    ],

];
