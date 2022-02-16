<?php

namespace App\Http\Controllers;

use App\Models\LogEntry;
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
        // Validate the request data
        // Both required and unit must be
        // either F or C
        $data = $request->validate([
            'city' => 'required',
            'unit' => 'required|in:F,C',
        ]);

        // Prepare the LogEntry
        $logEntry = LogEntry::make();
        $logEntry->description = '';
        $logEntry->request_city = $data['city'];
        $logEntry->request_unit = $data['unit'];

        // total number of minutes to be cached
        $minutes = 60;

        // Cache key
        $cacheItem = $data['unit'] . '-' . $data['city'] . '-' . 'forecast';

        $forecast = Cache::remember($cacheItem, $minutes, function () use ($data, $logEntry) {
            $logEntry->description = 'No cache used for this request';
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

        // LogEntry description depending if cache was used or not
        if ($logEntry->description === '') {
            $logEntry->description = 'Cache used for this request';
        }

        // Save log entry
        $logEntry->response_temperature = $forecast->temperature;
        $logEntry->response_forecast_description = $forecast->description;
        $logEntry->save();

        return $forecast;
    }

    /**
     * Open Weather api requires Metric param to return Celsius
     * and Imperial to return Fahrenheit.
     * This function depending on the request unit returns either
     * Metric or Imperial
     *
     * @param string $unit
     * @return string
     */
    private function getUnits($unit): string
    {
        return $unit === 'C' ? 'Metric' : 'Imperial';
    }

    /**
     * Function that scans the object created from JSON response
     * and finds exactly the forecast for the next 24 hours (at most +3 hours)
     *
     * @param array $data
     * @param object $responseJSON
     * @return object
     */
    private function createForecastFromResponseJSON($data, $responseJSON): object
    {
        $forecastList = $responseJSON->list;
        // Get the current date-time to compare it with the
        // dates from from the forecast
        $startTime = Carbon::now();
        foreach ($forecastList as $forecast) {
            // Get the date of the forecast entry
            $endTime = Carbon::parse($forecast->dt);

            // Check if forecast date difference is >= 24 Hours (+ 3 hours)
            // Open Weather gives forecasts for every 3 hours starting at 00:00
            // for each day.
            if ($endTime->diffInMinutes($startTime) >= 1440 && $endTime->diffInMinutes($startTime) <= 1620) {
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