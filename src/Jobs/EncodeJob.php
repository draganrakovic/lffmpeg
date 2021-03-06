<?php

namespace Ivebe\Lffmpeg\Jobs;

use Ivebe\Lffmpeg\Config\Config;
use Ivebe\Lffmpeg\Libs\Contracts\IEncodingLib;
use Ivebe\Lffmpeg\Services\Contracts\IVideoService;
use Psr\Log\LoggerInterface;

class EncodeJob extends BasicVideoJob
{

    private $quality;
    private $tmpFilename;
    private $destinationFilename;


    /**
     * EncodeJob constructor.
     * @param $videoID
     * @param null $quality
     */
    public function __construct( $videoID, $quality = null)
    {
        parent::__construct($videoID);

        $this->quality = $quality;
    }

    /**
     * @param IVideoService $videoService
     * @param IEncodingLib $encodingLib
     * @param LoggerInterface $log
     */
    private function initialize(IVideoService $videoService, IEncodingLib $encodingLib, LoggerInterface $log)
    {
        parent::init($videoService, $encodingLib, $log);
        $this->tmpFilename         = $this->videoService->getVideoTmpPathWithFilename($this->videoID, $this->quality);
        $this->destinationFilename = $this->videoService->getVideoPathWithFilename($this->videoID, $this->quality);
    }

    /**
     * @throws \Exception
     */
    private function encodeVideo(Config $config)
    {

        $src = $this->videoService->getVideoTmpPathWithFilename($this->videoID, null);

        $videoRepository = $this->videoService->getVideoRepository();


        $config = $config::get('encoding');

        if(!isset($config[$this->quality]))
            throw new \Exception("Unknown quality settings " . $this->quality);


        $w = $config[$this->quality]['w'];
        $h = $config[$this->quality]['h'];
        $b = $config[$this->quality]['b'];


        $this->encodingLib->encode($src, $this->tmpFilename, $w, $h, $b, function ($v, $f, $p) use($videoRepository) {
            $videoRepository->setProgress($this->videoID, $this->quality, $p);
        });
    }


    /**
     * Since job is serialized, we put DI into handle method, and call it over init function
     *
     * @param IVideoService $videoService
     * @param IEncodingLib $encodingLib
     * @param \Psr\Log\LoggerInterface $log
     */
    public function handle(IVideoService $videoService, IEncodingLib $encodingLib, LoggerInterface $log, Config $config)
    {
        $this->initialize($videoService, $encodingLib, $log);

        try {

            $this->encodeVideo($config);

            if(file_exists($this->destinationFilename))
                unlink($this->destinationFilename);


            if(!file_exists(dirname($this->destinationFilename)))
                mkdir(dirname($this->destinationFilename), 0777, true);

            rename($this->tmpFilename, $this->destinationFilename);

            $this->videoService
                ->getVideoRepository()
                ->setProgress($this->videoID, $this->quality, 100, 'DONE');

            $this->eventFinished(['quality' => $this->quality]);

        } catch (\Exception $e) {

            $this->videoService
                ->getVideoRepository()
                ->setProgress($this->videoID, $this->quality, false, 'FAILED');

            $this->log->error("{$this->videoID} encode job {$this->quality} failed: " . $e->getMessage());
        }
    }
}
