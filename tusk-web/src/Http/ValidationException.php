<?php

declare(strict_types=1);

namespace Tusk\Web\Http;

use Tusk\Validation\Violation;

final class ValidationException extends HttpException
{
    /** @var array<string, list<array{code: string, message: string}>> */
    private array $errors = [];

    /** @param list<Violation> $violations */
    public function __construct(array $violations)
    {
        parent::__construct(422, 'Validation Failed');

        foreach ($violations as $violation) {
            $this->errors[$violation->field()][] = [
                'code' => $violation->code(),
                'message' => $violation->message(),
            ];
        }
    }

    /** @return array<string, list<array{code: string, message: string}>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
