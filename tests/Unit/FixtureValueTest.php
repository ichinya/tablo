<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\Tests\Support\FixtureValue;
use Testo\Assert;
use Testo\Test;

final class FixtureValueTest
{
    #[Test]
    public function returnsActualObjectAndRowWithoutRepairOrValueCoercion(): void
    {
        $db = new \PDO('sqlite::memory:');
        Assert::same(FixtureValue::object($db), $db);
        $row = ['zero' => 0, 'empty' => '', 'null' => null, 'false' => false];
        Assert::same(FixtureValue::row($row), $row);
    }

    #[Test]
    public function absentValuesRefuseRatherThanManufacturingFixtureState(): void
    {
        foreach ([static fn () => FixtureValue::object(null), static fn () => FixtureValue::row(null)] as $call) {
            $caught = null;
            try { $call(); } catch (RuntimeException $error) { $caught = $error->getMessage(); }
            Assert::true(is_string($caught) && str_starts_with($caught, 'Required fixture '));
        }
    }

    #[Test]
    public function requiredIndexesAndRawRowsRetainFalseNullZeroAndEmptyValues(): void
    {
        $row = ['null' => null, 'false' => false, 'zero' => 0, 'empty' => ''];
        Assert::same(FixtureValue::fetched($row), $row);
        Assert::same(FixtureValue::databaseRow($row), $row);
        Assert::same(FixtureValue::databaseRow(false), false);
        Assert::same(FixtureValue::at($row, 'null'), null);
        Assert::same(FixtureValue::at($row, 'false'), false);
        Assert::same(FixtureValue::at($row, 'zero'), 0);
        Assert::same(FixtureValue::at($row, 'empty'), '');
        Assert::same(FixtureValue::string(''), '');
        Assert::same(FixtureValue::string('0'), '0');
        Assert::same(FixtureValue::integer(0), 0);
        Assert::same(FixtureValue::boolean(false), false);
        Assert::same(FixtureValue::boolean(true), true);
    }

    #[Test]
    public function indexScalarAndFetchedAdmissionRejectMissingAndWrongTypes(): void
    {
        $calls = [static fn () => FixtureValue::at(['present' => null], 'absent'),
            static fn () => FixtureValue::fetched(false), static fn () => FixtureValue::fetched(null),
            static fn () => FixtureValue::databaseRow(null), static fn () => FixtureValue::databaseRow(''),
            static fn () => FixtureValue::string(false), static fn () => FixtureValue::string(null),
            static fn () => FixtureValue::string(0), static fn () => FixtureValue::integer('0'),
            static fn () => FixtureValue::integer(false), static fn () => FixtureValue::integer(null),
            static fn () => FixtureValue::boolean(0), static fn () => FixtureValue::boolean(null)];
        foreach ($calls as $call) {
            $caught = null;
            try { $call(); } catch (RuntimeException $error) { $caught = $error; }
            Assert::instanceOf($caught, RuntimeException::class);
        }
    }
}
