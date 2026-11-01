-- Run after 1.15.1.sql. Adds the PowerPoint (slides) media type. Safe to repeat.
ALTER TABLE lessons MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz','assignment','slides') NOT NULL DEFAULT 'text';
ALTER TABLE lesson_media MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz','assignment','slides') NOT NULL DEFAULT 'text';
