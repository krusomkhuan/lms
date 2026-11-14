-- Run after 1.17.0.sql. Adds the Word Cloud board layout: short answers that many students give
-- are drawn larger. Safe to run again.
ALTER TABLE boards MODIFY COLUMN layout ENUM('wall','grid','columns','stream','timeline','table','freeform','map','wordcloud') NOT NULL DEFAULT 'wall';
