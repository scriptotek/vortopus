<?php

namespace App\Http\Controllers;

use App\Event;
use App\EventResource;
use App\Http\Requests;
use App\Presentation;
use App\Recording;
use App\YoutubePlaylist;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

class EventsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $events = Event::all();

        return response()->view('events.index', ['events' => $events]);
    }

    public function scrapeYouTubePage($youtubeId, &$data)
    {
        $recording = Recording::findOrFail($youtubeId);
        $data['p1_youtube_id'] = $recording->youtube_id;
        $data['title'] = array_get($recording->youtube_meta, 'title');
        $data['description'] = array_get($recording->youtube_meta, 'description');
    }

    public function vortexUrlFromText($text)
    {
        $vortex_pattern1 = '/(https?:\/\/www.ub.uio.no\/om\/aktuelt\/arrangementer\/[^\s]+)/';
        $vortex_pattern2 = '/(https?:\/\/www.ub.uio.no\/english\/about\/news-and-events\/events\/[^\s]+)/';
        if (!preg_match($vortex_pattern1, $text, $matches)) {
            if (!preg_match($vortex_pattern2, $text, $matches)) {
                return null;
            }
        }
        return $matches[1];
    }

    public function scrapeVortexPage(&$data, $url)
    {
        // Ex: http://bibsprut-dev.net:8000/events/create?from_vortex=http://www.ub.uio.no/om/aktuelt/arrangementer/ureal/science-debate/2015/biokonferansen2015.html
        $fb_pattern = '/https?:\/\/www\.facebook\.com\/events\/([0-9]+)/';

        $data['vortex_url'] = $url;
        // $data['description'] = preg_replace($vortex_pattern1, '', $data['description']);
        // $data['description'] = preg_replace($vortex_pattern2, '', $data['description']);

        // $data['description'] = preg_replace('/\n\n\n/', "\n\n", $data['description']);
        // $data['description'] = preg_replace('/\n\n\n/', "\n\n", $data['description']);

        $vortex = app('webdav')->get($data['vortex_url']);

        if (!$vortex) {
            die("Failed to get Vortex page $url");
        }

        $data['title'] = $vortex->properties->title;
        $data['description'] = strip_tags($vortex->properties->content);
        $data['location'] = $vortex->properties->location;

        if (isset($vortex->properties->{'start-date'})) {
            $dts = explode(' ', $vortex->properties->{'start-date'});
            $data['start_date'] = $dts[0];
            $data['p1_start_time'] = $dts[1];
        }

        if (isset($vortex->properties->{'end-date'})) {
            $dts = explode(' ', $vortex->properties->{'end-date'});
            $data['p1_end_time'] = $dts[1];
        }

        if (preg_match($fb_pattern, $vortex->properties->content, $matches2)) {
            $data['facebook_id'] = $matches2[1];
        }

    }

    public function getYoutubePlaylists()
    {
        $youtubePlaylists = ['0' => '(Ingen spilleliste)'];
        foreach (YoutubePlaylist::all() as $pl) {
            $youtubePlaylists[$pl->id] = $pl->title;
        }
        return $youtubePlaylists;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $data = [
            'uuid' => '',
            'title' => '',
            'intro' => '',
            'description' => '',
            'vortex_url' => '',
            'facebook_id' => '',
            'youtube_playlist_id' => -1,
            'youtube_playlists' => $this->getYoutubePlaylists(),
            'start_date' => '',
            'location' => 'Realfagsbiblioteket',

            'p1_youtube_id' => '',
            'p1_youtube_create' => true,
            'p1_person1' => '',
            'p1_start_time' => '15:00',
            'p1_end_time' => '16:00',
        ];
        if ($request->has('from_recording')) {
            $data['p1_youtube_create'] = false;
            $this->scrapeYouTubePage($request->get('from_recording'), $data);
            $vortexUrl = $this->vortexUrlFromText($data['description']);
            if (!is_null($vortexUrl)) {
                $this->scrapeVortexPage($data, $vortexUrl);
            }
            $data['description'] = trim($data['description']);
        }
        if ($request->has('from_vortex')) {
            // Make sure it's actually a vortex url:
            $vortexUrl = $this->vortexUrlFromText($request->get('from_vortex'));
            if (!is_null($vortexUrl)) {
                $this->scrapeVortexPage($data, $vortexUrl);
            }
        }
        return response()->view('events.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $event = new Event();
        $event->uuid = Uuid::uuid1()->toString();  // Time-based version1 string (for now)
        $event->title = $request->title;
        $event->intro = $request->intro;
        $event->description = $request->description;
        $event->vortex_url = $request->vortex_url;
        $event->facebook_id = $request->facebook_id;
        $event->start_date = new Carbon($request->start_date);
        $event->location = $request->location;

        if (!$request->youtube_playlist_id) {
            $event->youtube_playlist_id = null;
        } else {
            $youtubePlaylist = YoutubePlaylist::find($request->youtube_playlist_id);
            $event->youtube_playlist_id = $youtubePlaylist->id;
        }

        $event->save();

        // Organizers: TODO

        $presentation = new Presentation();
        $presentation->event_id = $event->id;
        $presentation->start_time = $request->p1_start_time;
        $presentation->end_time = $request->p1_end_time;
        // ...
        $presentation->save();

        if ($request->has('p1_person1')) {
            // TODO
        }

        if ($request->has('p1_youtube_id')) {
            $recording = Recording::where('youtube_id', '=', $request->p1_youtube_id)->first();
            if (is_null($recording)) {
                die("TODO: Video not found, redirect back with meaningful error message");
            }
            $recording->presentation_id = $presentation->id;
            $recording->save();
        }

        return redirect()->action('EventsController@show', $event->id)
            ->with('status', 'Arrangementet ble opprettet.');
    }

    /**
     * Display edit form for the resources
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function editResources($id)
    {
        $event = Event::findOrFail($id);
        $data = [
            'event' => $event,
            'resources' => $event->resources,
        ];
        return response()->view('events.resources', $data);
    }

    public function updateResources($id, Request $request)
    {
        $event = Event::findOrFail($id);
        foreach ($event->resources as $resource) {
            if ($request->has('attribution_' . $resource->id)) {
                $resource->attribution = $request->get('attribution_' . $resource->id);
            }
            if ($request->has('license_' . $resource->id)) {
                $resource->license = $request->get('license_' . $resource->id);
            }
            $resource->save();
        }
        return redirect()->action('EventsController@editResources', $event->id)
            ->with('status', 'Lagret.');

    }

    public function storeResource($id, Request $request)
    {

        $event = Event::findOrFail($id);

        $this->validate($request, [
            'file' => 'image|max:10000',
        ]);
        $file = $request->file('file');

        if ($file->isValid()) {

            $destination_path = public_path('uploads');

            list($width, $height, $type, $attr) = getimagesize($file->getPathname());

            $resource = $event->resources()->create([
                'original_filename' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'filetype' => 'image',
                'width' => $width,
                'height' => $height,
            ]);

            $extension  = $file->guessExtension();
            $filename = sha1($resource->id) . '.' . $extension;

            // \Storage::disk('cloud')->put(, file_get_contents($file->getPathname()) );

            $response = app('webdav')->put(
                'om/aktuelt/arrangementer/ureal/bilder/' . $filename,
                file_get_contents($file->getPathname())
            );

            if (!$response) {
                return response()->json('WebDav storage failed', 400);
            }

            $request->file('file')->move($destination_path, $filename);

            $resource->filename = $filename;
            $resource->save();

            return response()->json($resource->id, 200);
        } else {
            return response()->json('errors', 400);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $event = Event::findOrFail($id);
        $vortex = app('webdav')->get($event->vortex_url);

        $data = [
            'event' => $event,
            'vortex' => $vortex,
        ];

        return response()->view('events.show', $data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $event = Event::findOrFail($id);
        $p1 = $event->presentations[0];

        $data = [
            'uuid' => $event->uuid,
            'id' => $event->id,
            'title' => $event->title,
            'intro' => $event->intro,
            'description' => $event->description,
            'vortex_url' => $event->vortex_url,
            'facebook_id' => $event->facebook_id,
            'youtube_playlists' => $this->getYoutubePlaylists(),
            'youtube_playlist_id' => $event->youtube_playlist_id,
            'start_date' => $event->start_date,
            'location' => $event->location,

            'p1_youtube_id' => isset($p1->recording) ? $p1->recording->youtube_id : '',
            'p1_youtube_create' => false,
            'p1_person1' => '',
            'p1_start_time' => $p1->start_time,
            'p1_end_time' => $p1->end_time,
        ];

        return response()->view('events.create', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update($id, Request $request)
    {
        $event = Event::findOrFail($id);
        $event->title = $request->title;
        $event->intro = $request->intro;
        $event->description = $request->description;
        $event->vortex_url = $request->vortex_url;
        $event->facebook_id = $request->facebook_id;
        $event->start_date = new Carbon($request->start_date);
        $event->location = $request->location;
        if (!$request->youtube_playlist_id) {
            $event->youtube_playlist_id = null;
        } else {
            $youtubePlaylist = YoutubePlaylist::find($request->youtube_playlist_id);
            $event->youtube_playlist_id = $youtubePlaylist->id;
        }
        $event->save();

        // Organizers: TODO

        $p1 = $event->presentations[0];
        $p1->start_time = $request->p1_start_time;
        $p1->end_time = $request->p1_end_time;
        // ...
        $p1->save();

        if ($request->has('p1_person1')) {
            // TODO
        }

        if ($request->has('p1_youtube_id')) {
            $recording = Recording::where('presentation_id', '=', $p1->id)->first();
            if (!is_null($recording)) {
                if ($recording->id != $request->p1_youtube_id) {
                    $recording->presentation_id = null;
                }
            }

            $recording = Recording::where('youtube_id', '=', $request->p1_youtube_id)->first();
            if (is_null($recording)) {
                die("TODO: Video not found, redirect back with meaningful error message");
            }
            $recording->presentation_id = $p1->id;
            $recording->save();
        }

        return redirect()->action('EventsController@show', $event->id)
            ->with('status', 'Arrangementet ble oppdatert.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
