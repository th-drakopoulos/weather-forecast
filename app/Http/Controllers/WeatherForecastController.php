<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WeatherForecastController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'city' => 'required',
            'unit' => 'required|in:F,C',
        ]);
        $minutes = 60;
        $cacheItem = $data['city'] . 'forecast';
        $forecast = Cache::remember($cacheItem, $minutes, function () use ($data) {
            $app_key = config("weather.open_weather_app_key");
            $units = $this->getUnits($data['unit']);
            $city = $data['city'];
            $url = "https://api.openweathermap.org/data/2.5/forecast?q=${city}&appid=${app_key}&units=${units}";
            $client = new Client();
            $response = $client->get($url);
            if ($response->getStatusCode() === 200) {
                $responseJSON = json_decode($response->getBody());
                $forecast = $this->createForecastFromResponseJSON($data, $responseJSON);
            }
            return $forecast;
        });

        return $forecast;
    }

    private function getUnits($unit): string
    {
        return $unit === 'C' ? 'Metric' : 'Imperial';
    }

    private function createForecastFromResponseJSON($data, $responseJSON): object
    {
        $forecastList = $responseJSON->list;
        $startTime = Carbon::now();
        foreach ($forecastList as $forecast) {
            $endTime = Carbon::parse($forecast->dt);
            if ($endTime->diffInMinutes($startTime) <= 180) {
                return (object) [
                    'temperature' => $forecast->main->temp,
                    'description' => $forecast->weather[0]->description,
                ];
            }
        }
        return (object) [
            'temperature' => null,
            'description' => null,
        ];

    }
}