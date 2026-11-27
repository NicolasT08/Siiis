<?php

declare(strict_types=1);

namespace Siiis\Tests\Unit\Support;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Siiis\Support\ObjectIdValidator;

final class ObjectIdValidatorTest extends TestCase
{
    public function testAceptaIdsValidos(): void
    {
        self::assertTrue(ObjectIdValidator::isValid('650f1c2e8b3a4a0012345678'));
        self::assertInstanceOf(ObjectId::class, ObjectIdValidator::toObjectId('650f1c2e8b3a4a0012345678'));
    }

    public function testRechazaValoresInvalidosSinLanzar(): void
    {
        $invalidos = [
            '', 'xyz', '650f1c2e8b3a4a001234567', '650f1c2e8b3a4a00123456789', ['$ne' => 1], 123, null,
            // Con `$` en lugar de `\z`, un salto de línea final pasaba la validación y el driver lanzaba (500).
            "650f1c2e8b3a4a0012345678\n",
        ];
        foreach ($invalidos as $valor) {
            self::assertFalse(ObjectIdValidator::isValid($valor));
            self::assertNull(ObjectIdValidator::toObjectId($valor));
        }
    }
}
