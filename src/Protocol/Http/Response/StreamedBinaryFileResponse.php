<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Protocol\Http\Response;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamedBinaryFileResponse extends BinaryFileResponse implements StreamResponseInterface
{
    public function streamContent(): \Generator
    {
        try {
            if (!$this->isSuccessful()) {
                return $this;
            }

            if (0 === $this->maxlen) {
                return $this;
            }

            if ($this->tempFileObject instanceof \SplTempFileObject) {
                $file = $this->tempFileObject;
                $file->rewind();
            } else {
                $file = new \SplFileObject($this->file->getPathname(), 'r');
            }

            ignore_user_abort(true);

            if (0 !== $this->offset) {
                $file->fseek($this->offset);
            }

            $length = $this->maxlen;
            while ($length && !$file->eof()) {
                $read = ($length > $this->chunkSize || 0 > $length) ? $this->chunkSize : $length;
                $data = $file->fread($read);
                if ($data === false || $data === '') {
                    break;
                }
                yield $data;
                if (connection_aborted() !== 0) {
                    break;
                }
                if (0 < $length) {
                    $length -= strlen($data);
                }
            }
        } finally {
            if (!$this->tempFileObject instanceof \SplTempFileObject && $this->deleteFileAfterSend && is_file($this->file->getPathname())) {
                unlink($this->file->getPathname());
            }
        }

        return $this;
    }
}
