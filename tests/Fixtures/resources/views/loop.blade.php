@foreach (range(1, 3) as $number)
    {{ app('db')->select('select ? as number', [$number])[0]->number }}
@endforeach
