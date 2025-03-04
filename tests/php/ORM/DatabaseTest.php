<?php

namespace SilverStripe\ORM\Tests;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\MSSQL\MSSQLDatabase;
use SilverStripe\Dev\SapphireTest;
use Exception;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Tests\DatabaseTest\MyObject;

class DatabaseTest extends SapphireTest
{

    protected static $extra_dataobjects = [
        MyObject::class,
    ];

    protected $usesDatabase = true;

    public function testDontRequireField()
    {
        $schema = DB::get_schema();
        $this->assertArrayHasKey(
            'MyField',
            $schema->fieldList('DatabaseTest_MyObject')
        );

        $schema->dontRequireField('DatabaseTest_MyObject', 'MyField');

        $this->assertArrayHasKey(
            '_obsolete_MyField',
            $schema->fieldList('DatabaseTest_MyObject'),
            'Field is renamed to _obsolete_<fieldname> through dontRequireField()'
        );

        static::resetDBSchema(true);
    }

    public function testRenameField()
    {
        $schema = DB::get_schema();

        $schema->clearCachedFieldlist();

        $schema->renameField('DatabaseTest_MyObject', 'MyField', 'MyRenamedField');

        $this->assertArrayHasKey(
            'MyRenamedField',
            $schema->fieldList('DatabaseTest_MyObject'),
            'New fieldname is set through renameField()'
        );
        $this->assertArrayNotHasKey(
            'MyField',
            $schema->fieldList('DatabaseTest_MyObject'),
            'Old fieldname isnt preserved through renameField()'
        );

        static::resetDBSchema(true);
    }

    public function testMySQLCreateTableOptions()
    {
        if (!(DB::get_conn() instanceof MySQLDatabase)) {
            $this->markTestSkipped('MySQL only');
        }


        $ret = DB::query(
            sprintf(
                'SHOW TABLE STATUS WHERE "Name" = \'%s\'',
                'DatabaseTest_MyObject'
            )
        )->record();
        $this->assertEquals(
            $ret['Engine'],
            'InnoDB',
            "MySQLDatabase tables can be changed to InnoDB through DataObject::\$create_table_options"
        );
    }

    function testIsSchemaUpdating()
    {
        $schema = DB::get_schema();

        $this->assertFalse($schema->isSchemaUpdating(), 'Before the transaction the flag is false.');

        // Test complete schema update
        $test = $this;
        $schema->schemaUpdate(
            function () use ($test, $schema) {
                $test->assertTrue($schema->isSchemaUpdating(), 'During the transaction the flag is true.');
            }
        );
        $this->assertFalse($schema->isSchemaUpdating(), 'After the transaction the flag is false.');

        // Test cancelled schema update
        $schema->schemaUpdate(
            function () use ($test, $schema) {
                $schema->cancelSchemaUpdate();
                $test->assertFalse($schema->doesSchemaNeedUpdating(), 'After cancelling the transaction the flag is false');
            }
        );
    }

    public function testSchemaUpdateChecking()
    {
        $schema = DB::get_schema();

        // Initially, no schema changes necessary
        $test = $this;
        $schema->schemaUpdate(
            function () use ($test, $schema) {
                $test->assertFalse($schema->doesSchemaNeedUpdating());

                // If we make a change, then the schema will need updating
                $schema->transCreateTable("TestTable");
                $test->assertTrue($schema->doesSchemaNeedUpdating());

                // If we make cancel the change, then schema updates are no longer necessary
                $schema->cancelSchemaUpdate();
                $test->assertFalse($schema->doesSchemaNeedUpdating());
            }
        );
    }

    public function testHasTable()
    {
        $this->assertTrue(DB::get_schema()->hasTable('DatabaseTest_MyObject'));
        $this->assertFalse(DB::get_schema()->hasTable('asdfasdfasdf'));
    }

    public function testGetAndReleaseLock()
    {
        $db = DB::get_conn();

        if (!$db->supportsLocks()) {
            return $this->markTestSkipped('Tested database doesn\'t support application locks');
        }

        $this->assertTrue(
            $db->getLock('DatabaseTest'),
            'Can acquire lock'
        );
        // $this->assertFalse($db->getLock('DatabaseTest'), 'Can\'t repeatedly acquire the same lock');
        $this->assertTrue(
            $db->getLock('DatabaseTest'),
            'The same lock can be acquired multiple times in the same connection'
        );

        $this->assertTrue(
            $db->getLock('DatabaseTestOtherLock'),
            'Can acquire different lock'
        );
        $db->releaseLock('DatabaseTestOtherLock');

        // Release potentially stacked locks from previous getLock() invocations
        $db->releaseLock('DatabaseTest');
        $db->releaseLock('DatabaseTest');

        $this->assertTrue(
            $db->getLock('DatabaseTest'),
            'Can acquire lock after releasing it'
        );
        $db->releaseLock('DatabaseTest');
    }

    public function testCanLock()
    {
        $db = DB::get_conn();

        if (!$db->supportsLocks()) {
            return $this->markTestSkipped('Database doesn\'t support locks');
        }

        if ($db instanceof MSSQLDatabase) {
            return $this->markTestSkipped('MSSQLDatabase doesn\'t support inspecting locks');
        }

        $this->assertTrue($db->canLock('DatabaseTest'), 'Can lock before first acquiring one');
        $db->getLock('DatabaseTest');
        $this->assertFalse($db->canLock('DatabaseTest'), 'Can\'t lock after acquiring one');
        $db->releaseLock('DatabaseTest');
        $this->assertTrue($db->canLock('DatabaseTest'), 'Can lock again after releasing it');
    }

    public function testFieldTypes()
    {
        // Scaffold some data
        $obj = new MyObject();
        $obj->MyField = "value";
        $obj->MyInt = 5;
        $obj->MyFloat = 6.0;

        // Note: in SQLite, whole numbers of a decimal field will be returned as integers rather than floats
        $obj->MyDecimal = 7.1;
        $obj->MyBoolean = true;
        $obj->write();

        $record = DB::prepared_query(
            'SELECT * FROM "DatabaseTest_MyObject" WHERE "ID" = ?',
            [ $obj->ID ]
        )->record();

        // IDs and ints are returned as ints
        $this->assertIsInt($record['ID'], 'Primary key should be integer');
        $this->assertIsInt($record['MyInt'], 'DBInt fields should be integer');

        $this->assertIsFloat($record['MyFloat'], 'DBFloat fields should be float');
        $this->assertIsFloat($record['MyDecimal'], 'DBDecimal fields should be float');

        // Booleans are returned as ints – we follow MySQL's lead
        $this->assertIsInt($record['MyBoolean'], 'DBBoolean fields should be int');

        // Strings and enums are returned as strings
        $this->assertIsString($record['MyField'], 'DBVarchar fields should be string');
        $this->assertIsString($record['ClassName'], 'DBEnum fields should be string');

        // Dates are returned as strings
        $this->assertIsString($record['Created'], 'DBDatetime fields should be string');


        // Ensure that the same is true when calling a query a second time (cached prepared statement)

        $record = DB::prepared_query(
            'SELECT * FROM "DatabaseTest_MyObject" WHERE "ID" = ?',
            [ $obj->ID ]
        )->record();

        // IDs and ints are returned as ints
        $this->assertIsInt($record['ID'], 'Primary key should be integer (2nd call)');
        $this->assertIsInt($record['MyInt'], 'DBInt fields should be integer (2nd call)');

        $this->assertIsFloat($record['MyFloat'], 'DBFloat fields should be float (2nd call)');
        $this->assertIsFloat($record['MyDecimal'], 'DBDecimal fields should be float (2nd call)');

        // Booleans are returned as ints – we follow MySQL's lead
        $this->assertIsInt($record['MyBoolean'], 'DBBoolean fields should be int (2nd call)');

        // Strings and enums are returned as strings
        $this->assertIsString($record['MyField'], 'DBVarchar fields should be string (2nd call)');
        $this->assertIsString($record['ClassName'], 'DBEnum fields should be string (2nd call)');

        // Dates are returned as strings
        $this->assertIsString($record['Created'], 'DBDatetime fields should be string (2nd call)');


        // Ensure that the same is true when using non-prepared statements
        $record = DB::query('SELECT * FROM "DatabaseTest_MyObject" WHERE "ID" = ' . (int)$obj->ID)->record();

        // IDs and ints are returned as ints
        $this->assertIsInt($record['ID'], 'Primary key should be integer (non-prepared)');
        $this->assertIsInt($record['MyInt'], 'DBInt fields should be integer (non-prepared)');

        $this->assertIsFloat($record['MyFloat'], 'DBFloat fields should be float (non-prepared)');
        $this->assertIsFloat($record['MyDecimal'], 'DBDecimal fields should be float (non-prepared)');

        // Booleans are returned as ints – we follow MySQL's lead
        $this->assertIsInt($record['MyBoolean'], 'DBBoolean fields should be int (non-prepared)');

        // Strings and enums are returned as strings
        $this->assertIsString($record['MyField'], 'DBVarchar fields should be string (non-prepared)');
        $this->assertIsString($record['ClassName'], 'DBEnum fields should be string (non-prepared)');

        // Dates are returned as strings
        $this->assertIsString($record['Created'], 'DBDatetime fields should be string (non-prepared)');

        // Booleans selected directly are ints
        $result = DB::query('SELECT TRUE')->record();
        $this->assertIsInt(reset($result));
    }

    /**
     * Test that repeated iteration of a query returns all records.
     * See https://github.com/silverstripe/silverstripe-framework/issues/9097
     */
    public function testRepeatedIteration()
    {
        $inputData = ['one', 'two', 'three', 'four'];

        foreach ($inputData as $i => $text) {
            $x = new MyObject();
            $x->MyField = $text;
            $x->MyInt = $i;
            $x->write();
        }

        $query = DB::query('SELECT "MyInt", "MyField" FROM "DatabaseTest_MyObject" ORDER BY "MyInt"');

        $this->assertEquals($inputData, $query->map());
        $this->assertEquals($inputData, $query->map());
    }

    /**
     * Test that repeated abstracted iteration of a query returns all records.
     */
    public function testRepeatedIterationUsingAbstraction()
    {
        $inputData = ['one', 'two', 'three', 'four'];

        foreach ($inputData as $i => $text) {
            $x = new MyObject();
            $x->MyField = $text;
            $x->MyInt = $i;
            $x->write();
        }

        $select = SQLSelect::create(['"MyInt"', '"MyField"'], '"DatabaseTest_MyObject"', orderby: ['"MyInt"']);

        $this->assertEquals($inputData, $select->execute()->map());
        $this->assertEquals($inputData, $select->execute()->map());
    }

    /**
     * Test that stopping iteration part-way through produces predictable results
     * on a subsequent iteration.
     * This test is here to ensure consistency between implementations (e.g. mysql vs postgres, etc)
     */
    public function testRepeatedPartialIteration()
    {
        $inputData = ['one', 'two', 'three', 'four'];

        foreach ($inputData as $i => $text) {
            $x = new MyObject();
            $x->MyField = $text;
            $x->MyInt = $i;
            $x->write();
        }

        $query = DB::query('SELECT "MyInt", "MyField" FROM "DatabaseTest_MyObject" ORDER BY "MyInt"');

        $i = 0;
        foreach ($query as $record) {
            $this->assertEquals($inputData[$i], $record['MyField']);
            $i++;
            if ($i > 1) {
                break;
            }
        }

        // Continue from where we left off, since we're using a Generator
        foreach ($query as $record) {
            $this->assertEquals($inputData[$i], $record['MyField']);
            $i++;
        }
    }
}
