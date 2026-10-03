<?php

namespace RuntimeLens\Tests\Fixtures\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use RuntimeException;

class FixtureController
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function clean(): string
    {
        $this->db->select('select 1');

        return 'ok';
    }

    public function repeated(): string
    {
        foreach (range(1, 5) as $number) {
            $this->db->select('select ? as number', [$number]);
        }

        return 'ok';
    }

    public function inLists(): string
    {
        $this->db->select('select 1 where 1 in (?, ?, ?)', [1, 2, 3]);
        $this->db->select('select 1 where 1 in (?, ?)', [1, 2]);

        return 'ok';
    }

    public function manyQueries(): string
    {
        foreach (range(1, 12) as $ignored) {
            $this->db->select('select 1');
        }

        return 'ok';
    }

    public function hugeInList(): string
    {
        $bindings = range(1, 30000);
        $this->db->select('select 1 where 1 in ('.implode(', ', array_fill(0, count($bindings), '?')).')', $bindings);

        return 'ok';
    }

    public function binaryBinding(): string
    {
        $this->db->select('select ? as value', ["\xB1\x31"]);

        return 'ok';
    }

    public function view(): View
    {
        return view('loop');
    }

    public function httpCall(Factory $http): string
    {
        $query = http_build_query(['token' => 'secret-token']);
        $http->get("https://api.example.test/items?{$query}");

        return 'ok';
    }

    public function httpFailure(Factory $http): string
    {
        try {
            $http->timeout(2)->get('http://127.0.0.1:9/down');
        } catch (ConnectionException) {
            return 'down';
        }

        return 'ok';
    }

    public function boom(): string
    {
        throw new RuntimeException('Fixture boom');
    }

    public function notFound(): string
    {
        abort(404);
    }
}
