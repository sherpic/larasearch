<?php

namespace Gtk\Larasearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class MakeSearchable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The models to be made searchable.
     *
     * @var \Illuminate\Database\Eloquent\Collection
     */
    public $models;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function __construct($models)
    {
        $this->models = $models;
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->models->first()->searchableUsing()->update($this->models);
    }
}
